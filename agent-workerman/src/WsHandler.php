<?php

declare(strict_types=1);

namespace DmsAgent;

use DmsAgent\Mysql\AsyncClient;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;

/**
 * WsHandler — 单浏览器连接全生命周期（协议 v2）
 * 与 agent/src/ConnectionHandler.php（TypePHP 版）功能对齐，基于 Workerman 事件驱动
 *
 * 消息：connect / binlog-dump / query / close / heartbeat
 * 响应：connected / dump-started / binlog-event / query-result / error / heartbeat
 *
 * 每连接一个实例（存放在 $conn->context->handler），并持有独立的 MySQL 异步连接，
 * 因此支持多个浏览器同时追踪（原 TypePHP 版为单连接阻塞模型）。
 *
 * 连接模型：dump 长驻一条 MySQL 连接（ST_BINLOG 事件流）；query 走独立的惰性 MySQL 连接，
 * 因此追踪期间仍可补元数据 / 查库表（前端 query 的 database 字段在此生效）。
 */
final class WsHandler
{
    private TcpConnection $conn;
    private ?AsyncClient $mysql = null;
    /** 查询专用连接（与 dump 主连接分离；dump 长驻 ST_BINLOG 时 query 仍可执行） */
    private ?AsyncClient $queryMysql = null;
    private bool $connected = false;
    private bool $dumping = false;
    private int $serverId;
    private string $binlogFile = '';

    /** 最近一次 connect 参数（query 连接惰性重建用） */
    private string $mysqlHost = '';
    private int $mysqlPort = 3306;
    private string $mysqlUser = '';
    private string $mysqlPassword = '';
    private string $mysqlDatabase = '';
    private int $mysqlTimeoutMs = 0;
    /** 查询连接正在建立中（防止并发 query 重复建连） */
    private bool $queryConnecting = false;
    /** 串行查询队列：并发帧（如 StrictMode 双挂载连发）排队执行，不再回错误帧 */
    private array $queryQueue = [];

    /** @var int|false 心跳定时器 id */
    private $heartbeatTimer = false;

    /** krowinski 解析子进程（真实 binlog 解析：NEWDECIMAL/DATETIME2 等由库解码，绕过 WASM 手写解码短板） */
    private $dumpProc = null;
    /** 子进程 stdout 重定向文件（Windows 下 proc_open 管道 stream_set_blocking(false) 无效，fread 会阻塞事件循环） */
    private string $dumpOutFile = '';
    /** 已读文件偏移（增量轮询） */
    private int $dumpOffset = 0;
    /** @var int|false stdout 轮询定时器 id */
    private $dumpTimer = false;
    /** 行缓冲（JSON 行可能跨多次读取） */
    private string $dumpBuffer = '';
    /** 子进程 stderr 重定向文件（异常退出时读取错误尾巴） */
    private string $dumpErrFile = '';

    public function __construct(TcpConnection $conn)
    {
        $this->conn = $conn;
        $this->serverId = random_int(1, 2147483647);
        $this->heartbeatTimer = Timer::add(
            AgentConstants::HEARTBEAT_INTERVAL_MS / 1000,
            function (): void {
                $this->sendHeartbeat();
            }
        );
    }

    /**
     * Workerman websocket 协议已自动完成握手与 ping/pong，
     * 这里收到的是完整文本帧载荷（JSON 字符串）。
     */
    public function onMessage(string $data): void
    {
        $frame = json_decode($data, true);
        if (!is_array($frame) || !isset($frame['type'])) {
            $this->sendError('', AgentConstants::PROTOCOL_ERROR, '非 JSON 帧或缺少 type 字段');
            return;
        }
        $type = (string) $frame['type'];
        $id = (string) ($frame['id'] ?? '');
        $payload = is_array($frame['payload'] ?? null) ? $frame['payload'] : [];

        switch ($type) {
            case 'connect':
                $this->handleConnect($id, $payload);
                break;
            case 'binlog-dump':
                $this->handleBinlogDump($id, $payload);
                break;
            case 'query':
                $this->handleQuery($id, $payload);
                break;
            case 'close':
                $this->handleClose();
                break;
            case 'heartbeat':
                $this->sendHeartbeat();
                break;
            default:
                $this->sendError('', AgentConstants::PROTOCOL_ERROR, '未知消息类型: ' . $type);
                break;
        }
    }

    /** WS 连接关闭：清理定时器与 MySQL 连接 */
    public function onClose(): void
    {
        if ($this->heartbeatTimer !== false) {
            Timer::del($this->heartbeatTimer);
            $this->heartbeatTimer = false;
        }
        if ($this->mysql !== null) {
            $this->mysql->close();
            $this->mysql = null;
        }
        $this->teardownQuery();
        $this->teardownDumpWorker();
        $this->connected = false;
        $this->dumping = false;
    }

    // ─── 消息处理 ─────────────────────────────────────────

    private function handleConnect(string $id, array $payload): void
    {
        // 连接已建立或正在建立中（覆盖进行中的 connect），拒绝重复发起
        if ($this->mysql !== null) {
            $this->sendError($id, AgentConstants::INVALID_PARAM, '连接已建立或正在建立中，请先 close');
            return;
        }
        $host = (string) ($payload['host'] ?? '');
        $port = (int) ($payload['port'] ?? 3306);
        $user = (string) ($payload['user'] ?? '');
        $password = (string) ($payload['password'] ?? '');
        $database = (string) ($payload['database'] ?? '');
        $timeout = (int) ($payload['connectTimeoutMs'] ?? AgentConstants::CONNECT_TIMEOUT_MS);
        $clientId = (int) ($payload['serverId'] ?? 0);

        if ($host === '') {
            $this->sendError($id, AgentConstants::INVALID_PARAM, 'host 不能为空');
            return;
        }
        if ($clientId > 0) {
            $this->serverId = $clientId;
        }
        $this->mysqlHost = $host;
        $this->mysqlPort = $port;
        $this->mysqlUser = $user;
        $this->mysqlPassword = $password;
        $this->mysqlDatabase = $database;
        $this->mysqlTimeoutMs = $timeout;

        $mysql = new AsyncClient();
        $this->mysql = $mysql;

        $self = $this;
        $mysql->connect(
            $host,
            $port,
            $user,
            $password,
            $database,
            (int) ceil($timeout / 1000),
            function () use ($self, $id, $mysql): void {
                // 陈旧回调（连接已被替换/关闭）直接忽略
                if ($self->mysql !== $mysql) {
                    return;
                }
                $self->onMysqlConnected($id);
            },
            function (int $code, string $message) use ($self, $id, $mysql): void {
                if ($self->mysql !== $mysql) {
                    return;
                }
                $self->sendError($id, $code, $message);
                $self->teardownMysql();
            }
        );
    }

    private function onMysqlConnected(string $id): void
    {
        if ($this->mysql === null) {
            return;
        }
        $this->connected = true;
        $meta = new MetaGatherer($this->mysql, $this->serverId);
        $self = $this;
        $meta->gather(function (array $metaData) use ($self, $id): void {
            if (($metaData['hasBinlog'] ?? false) === true) {
                $self->binlogFile = (string) ($metaData['binlogFile'] ?? '');
            }
            $self->sendFrame($id, 'connected', $metaData);
        });
    }

    private function handleBinlogDump(string $id, array $payload): void
    {
        if (!$this->connected || $this->mysqlHost === '') {
            $this->sendError($id, AgentConstants::PROXY_NOT_READY, '尚未连接 MySQL');
            return;
        }
        if ($this->dumping) {
            $this->sendError($id, AgentConstants::INVALID_PARAM, '已在追踪中');
            return;
        }
        $fileName = (string) ($payload['binlogFile'] ?? $this->binlogFile);
        $filePos = (int) ($payload['binlogPos'] ?? 4);

        if ($fileName === '') {
            $this->sendError($id, AgentConstants::INVALID_PARAM, 'binlogFile 不能为空');
            return;
        }

        // 时间窗口（前端传 epoch 毫秒，转秒给 worker；0 = 不限）
        $startTs = (int) ($payload['startMs'] ?? 0) > 0 ? intdiv((int) $payload['startMs'], 1000) : 0;
        $endTs = (int) ($payload['endMs'] ?? 0) > 0 ? intdiv((int) $payload['endMs'], 1000) : 0;

        $this->dumping = true;
        $worker = __DIR__ . '/../bin/krowinski_dump.php';
        if (!is_file($worker)) {
            $this->dumping = false;
            $this->sendError($id, AgentConstants::INTERNAL_ERROR, 'krowinski 解析脚本缺失: bin/krowinski_dump.php');
            return;
        }
        // 密码走环境变量，避免出现在进程列表；env 必须基于 getenv() 全量合并——
        // Windows 下 proc_open 会整体替换子进程环境，缺 PATH/SYSTEMROOT 会导致
        // Winsock 初始化失败（socket_create 10106），krowinski 无法建连
        $env = array_merge(getenv(), [
            'DMS_MYSQL_PASSWORD' => $this->mysqlPassword,
        ]);
        $cmd = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($worker)
            . ' --host ' . escapeshellarg($this->mysqlHost)
            . ' --port ' . $this->mysqlPort
            . ' --user ' . escapeshellarg($this->mysqlUser)
            . ' --file ' . escapeshellarg($fileName)
            . ' --pos ' . $filePos;
        if ($startTs > 0) {
            $cmd .= ' --start-ts ' . $startTs;
        }
        if ($endTs > 0) {
            $cmd .= ' --end-ts ' . $endTs;
        }
        // stdout/stderr 重定向到文件而非管道：Windows 上 proc_open 管道的
        // stream_set_blocking(false) 无效，fread 会阻塞直到子进程输出，
        // 长驻 dump（追平文件尾后无输出）会把 Workerman 事件循环卡死，
        // 表现为 dump 期间 query/heartbeat 全部无响应。
        $runtime = dirname(__DIR__) . '/runtime';
        if (!is_dir($runtime)) {
            @mkdir($runtime, 0777, true);
        }
        $outFile = $runtime . '/krowinski_' . $this->serverId . '_' . bin2hex(random_bytes(4)) . '.out';
        $errFile = $runtime . '/krowinski_' . $this->serverId . '_' . bin2hex(random_bytes(4)) . '.err';
        $proc = proc_open(
            $cmd,
            [1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']],
            $pipes,
            dirname(__DIR__),
            $env,
            ['bypass_shell' => true]
        );
        if (!is_resource($proc)) {
            $this->dumping = false;
            $this->sendError($id, AgentConstants::INTERNAL_ERROR, '无法启动 krowinski 解析进程');
            return;
        }
        $this->dumpProc = $proc;
        $this->dumpOutFile = $outFile;
        $this->dumpErrFile = $errFile;
        $this->dumpOffset = 0;
        $this->dumpBuffer = '';
        $self = $this;
        $this->dumpTimer = Timer::add(0.1, function () use ($self): void {
            $self->drainDumpWorker();
        }, null, true);
        $this->sendFrame($id, 'dump-started', [
            'binlogFile' => $fileName,
            'binlogPos' => $filePos,
        ]);
    }

    /** 轮询子进程 stdout 文件增量，逐行转发 binlog-change 帧；进程退出时清理 */
    private function drainDumpWorker(): void
    {
        if ($this->dumpProc === null || $this->dumpOutFile === '') {
            return;
        }
        $h = @fopen($this->dumpOutFile, 'rb');
        if ($h !== false) {
            fseek($h, $this->dumpOffset);
            $new = stream_get_contents($h);
            $this->dumpOffset = ftell($h);
            fclose($h);
            if ($new !== '' && $new !== false) {
                $this->dumpBuffer .= $new;
                while (($nl = strpos($this->dumpBuffer, "\n")) !== false) {
                    $line = trim(substr($this->dumpBuffer, 0, $nl));
                    $this->dumpBuffer = substr($this->dumpBuffer, $nl + 1);
                    if ($line === '') {
                        continue;
                    }
                    $obj = json_decode($line, true);
                    if (!is_array($obj) || ($obj['type'] ?? '') !== 'change') {
                        continue;
                    }
                    $this->sendFrame('', 'binlog-change', [
                        'kind' => (string) ($obj['kind'] ?? ''),
                        'schema' => (string) ($obj['schema'] ?? ''),
                        'table' => (string) ($obj['table'] ?? ''),
                        'columns' => (array) ($obj['columns'] ?? []),
                        'primaryKeys' => (array) ($obj['primaryKeys'] ?? []),
                        'before' => $obj['before'] ?? null,
                        'after' => $obj['after'] ?? null,
                        'xid' => (int) ($obj['xid'] ?? 0),
                        'timestamp' => (int) ($obj['timestamp'] ?? 0),
                        'binlogFile' => (string) ($obj['binlogFile'] ?? ''),
                        'binlogPos' => (int) ($obj['binlogPos'] ?? 0),
                    ]);
                }
            }
        }
        $st = proc_get_status($this->dumpProc);
        if (!$st['running']) {
            $exitCode = (int) $st['exitcode'];
            if ($exitCode === 0) {
                // worker 正常退出（历史窗口已越过 endTs / 达到上限）：通知前端采集完成
                $this->sendFrame('', 'binlog-end', ['exitCode' => 0]);
            } else {
                $errTail = '';
                if ($this->dumpErrFile !== '' && is_file($this->dumpErrFile)) {
                    $errTail = trim((string) @file_get_contents($this->dumpErrFile));
                    $errTail = substr($errTail, -200);
                }
                $this->sendError('', AgentConstants::INTERNAL_ERROR, 'binlog 解析进程异常退出 code=' . $exitCode . ($errTail !== '' ? ': ' . $errTail : ''));
            }
            $this->teardownDumpWorker();
        }
    }

    /** 结束 krowinski 子进程（kill + 停轮询 + 清理临时文件） */
    private function teardownDumpWorker(): void
    {
        if ($this->dumpTimer !== false) {
            Timer::del($this->dumpTimer);
            $this->dumpTimer = false;
        }
        if ($this->dumpProc !== null) {
            $st = proc_get_status($this->dumpProc);
            if ($st['running']) {
                proc_terminate($this->dumpProc);
            }
            proc_close($this->dumpProc);
            $this->dumpProc = null;
        }
        if ($this->dumpOutFile !== '') {
            @unlink($this->dumpOutFile);
            $this->dumpOutFile = '';
        }
        if ($this->dumpErrFile !== '') {
            @unlink($this->dumpErrFile);
            $this->dumpErrFile = '';
        }
        $this->dumpOffset = 0;
        $this->dumpBuffer = '';
        $this->dumping = false;
    }

    private function relayEvent(array $event): void
    {
        $this->sendFrame('', 'binlog-event', [
            'raw' => (string) ($event['raw'] ?? ''),
            'eventType' => (int) ($event['eventType'] ?? 0),
            'binlogFile' => (string) ($event['binlogFile'] ?? ''),
            'binlogPos' => (int) ($event['binlogPos'] ?? 0),
            'timestamp' => (int) ($event['timestamp'] ?? 0),
            'serverId' => (int) ($event['serverId'] ?? 0),
        ]);
    }

    private function handleQuery(string $id, array $payload): void
    {
        if (!$this->connected || $this->mysqlHost === '') {
            $this->sendError($id, AgentConstants::PROXY_NOT_READY, '尚未连接 MySQL');
            return;
        }
        $sql = (string) ($payload['sql'] ?? '');
        $trimmed = trim($sql);
        if (!preg_match('/^\s*(SELECT|SHOW)\b.*$/i', $trimmed)) {
            $this->sendError($id, AgentConstants::PROTOCOL_ERROR, '仅允许只读查询');
            return;
        }
        // 帧内 database 优先（前端 useSchemaMeta 查表会传目标库），缺省沿用连接库
        $database = trim((string) ($payload['database'] ?? ''));
        // 单 MySQL 连接一次只能执行一条查询：并发帧（如 React StrictMode 双挂载连发
        // 库列表）排队串行执行，不再回错误帧——错误帧会令前端下拉被禁用
        $this->queryQueue[] = ['id' => $id, 'sql' => $sql, 'database' => $database];
        $this->drainQueryQueue();
    }

    /** 队列非空且查询连接空闲（或需建连）时执行队首；busy/建连中则等完成回调再 drain */
    private function drainQueryQueue(): void
    {
        if ($this->queryQueue === []) {
            return;
        }
        if ($this->queryConnecting) {
            return;
        }
        $client = $this->queryMysql;
        if ($client !== null && !$client->isAlive()) {
            // 连接已断：丢弃后重建
            $client->close();
            $this->queryMysql = null;
        } elseif ($client !== null && !$client->isConnected()) {
            // 上一条查询执行中：完成回调里会再 drain
            return;
        }
        // peek 队首，不提前出队：建连是异步的，出队须等到真正派发（直接执行/建连完成）时，
        // 否则首条查询会在建连期间丢失（连接就绪后队列已空，永不执行）
        $this->execQuery($this->queryQueue[0]);
    }

    /** 执行单条查询：连接空闲且库匹配直接发，否则（重新）建连后发 */
    private function execQuery(array $item): void
    {
        $id = (string) $item['id'];
        $sql = (string) $item['sql'];
        $db = $item['database'] !== '' ? (string) $item['database'] : $this->mysqlDatabase;
        $client = $this->queryMysql;
        if ($client !== null && $client->isConnected() && $client->getCurrentDatabase() === $db) {
            array_shift($this->queryQueue);
            $self = $this;
            $client->query(
                $sql,
                function (array $result) use ($self, $id): void {
                    $colOut = [];
                    foreach (($result['columns'] ?? []) as $col) {
                        $colOut[] = [
                            'name' => (string) ($col['name'] ?? ''),
                            'type' => (string) ($col['type'] ?? ''),
                        ];
                    }
                    $self->sendFrame($id, 'query-result', [
                        'columns' => $colOut,
                        'rows' => $result['rows'] ?? [],
                    ]);
                    $self->drainQueryQueue();
                },
                function (int $code, string $message) use ($self, $id): void {
                    $self->sendError($id, $code, $message);
                    $self->drainQueryQueue();
                }
            );
            return;
        }
        if ($client !== null) {
            // 库不匹配或连接不可用：关旧建新（重认证）
            $client->close();
            $this->queryMysql = null;
        }
        $self = $this;
        $this->queryConnecting = true;
        $fresh = new AsyncClient();
        $this->queryMysql = $fresh;
        $timeout = max(1, (int) ceil(($this->mysqlTimeoutMs > 0 ? $this->mysqlTimeoutMs : AgentConstants::CONNECT_TIMEOUT_MS) / 1000));
        $fresh->connect(
            $this->mysqlHost,
            $this->mysqlPort,
            $this->mysqlUser,
            $this->mysqlPassword,
            $db,
            $timeout,
            function () use ($self): void {
                $self->queryConnecting = false;
                $self->drainQueryQueue();
            },
            function (int $code, string $message) use ($self, $fresh, $id): void {
                $self->queryConnecting = false;
                if ($self->queryMysql === $fresh) {
                    $self->queryMysql = null;
                }
                // 队首在此次建连失败中终结：出队后报错，避免 drain 反复重建同一查询
                array_shift($self->queryQueue);
                $self->sendError($id, $code, $message);
                $self->drainQueryQueue();
            }
        );
    }

    private function handleClose(): void
    {
        $this->dumping = false;
        $this->connected = false;
        $this->teardownMysql();
        $this->teardownQuery();
        $this->teardownDumpWorker();
        // Workerman websocket 协议会发送 close 帧并关闭连接
        $this->conn->close();
    }



    // ─── 发送辅助 ─────────────────────────────────────────

    private function sendError(string $id, int $code, string $message): void
    {
        $this->sendFrame($id, 'error', ['code' => $code, 'message' => $message]);
    }

    private function sendHeartbeat(): void
    {
        $this->sendFrame('', 'heartbeat', [
            'ts' => self::now(),
            'binlogPos' => null,
        ]);
    }

    private function sendFrame(string $id, string $type, array $payload): void
    {
        if ($this->conn->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
            return;
        }
        $json = json_encode([
            'v' => AgentConstants::PROTOCOL_VERSION,
            'id' => $id,
            'type' => $type,
            'ts' => self::now(),
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $this->conn->send($json);
    }

    private function teardownMysql(): void
    {
        if ($this->mysql !== null) {
            $this->mysql->close();
            $this->mysql = null;
        }
    }

    private function teardownQuery(): void
    {
        if ($this->queryMysql !== null) {
            $this->queryMysql->close();
            $this->queryMysql = null;
        }
        $this->queryConnecting = false;
        $this->queryQueue = [];
    }

    private static function now(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
