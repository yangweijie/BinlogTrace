<?php

declare(strict_types=1);

namespace DmsAgent;

use DmsAgent\Mysql\KrowinskiQueryAdapter;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Chunk;
use Webman\Http\Response;
use Workerman\Timer;

/**
 * WsHandler — HTTP 模式下的会话全生命周期（协议 v2）
 * 与 agent/src/ConnectionHandler.php（TypePHP 版）功能对齐，基于 Workerman 事件驱动。
 *
 * 原 WebSocket 模型下每个浏览器 WS 连接持有一个 WsHandler（存在 $conn->context）；
 * HTTP 无连接，改为 session token 关联：connect 时注册到 SessionManager 并回传 token，
 * 后续 dump/query/close 请求带 token 取回同一实例，复用其 MySQL 连接与 dump 状态。
 *
 * 路由：POST /connect（请求-响应）、POST /dump（SSE 流式）、POST /query（请求-响应）、POST /close
 *
 * 发送模型：
 *  - 普通请求（connect/query）：每请求只回一帧（connected / query-result / error），用 respondOnce()。
 *  - dump 请求：先发 text/event-stream 响应头，之后每个 binlog-change/heartbeat/error/binlog-end
 *    作为一个 SSE event（"data: {frame}\n\n"）持续写出，结束或错误时关闭 SSE 连接。
 */
final class WsHandler
{
    private ?TcpConnection $conn = null;
    /** SSE（dump）长连接的独立引用，便于 close 请求时单独关闭它 */
    private ?TcpConnection $sseConn = null;
    private bool $sse = false;
    private string $sessionToken = '';

    /** 主连接：krowinski 适配器（用于 connect 认证 + meta 采集）；binlog-dump 仍用 AsyncClient 子进程 */
    private ?KrowinskiQueryAdapter $mysql = null;
    /** 查询专用连接（krowinski PDO 适配器；与 AsyncClient binlog-dump 分离） */
    private ?KrowinskiQueryAdapter $queryMysql = null;
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

    public function __construct()
    {
        $this->serverId = random_int(1, 2147483647);
        $this->heartbeatTimer = Timer::add(
            AgentConstants::HEARTBEAT_INTERVAL_MS / 1000,
            function (): void {
                $this->sendHeartbeat();
            }
        );
    }

    // ─── HTTP 入口 ─────────────────────────────────────────

    /** POST /connect — 建立 MySQL 连接并采集元数据，回传 connected（含 session token）或 error */
    public function onConnect(TcpConnection $connection, array $frame): Response
    {
        $this->conn = $connection;
        $this->sse = false;
        $payload = is_array($frame['payload'] ?? null) ? $frame['payload'] : [];
        $id = (string) ($frame['id'] ?? '');
        return $this->handleConnect($id, $payload);
    }

    /** POST /dump — 启动 binlog 追踪，以 SSE 流持续推送变更。
     *  绕过 webman HTTP 协议编码（其会加 Content-Length 阻断流式），
     *  直接设置 protocol=null 后发送裸 HTTP 响应头 + 裸 data 帧（与 agent-workerman 一致）。 */
    public function onDump(TcpConnection $connection, array $frame): Response
    {
        $this->conn = $connection;
        $this->sseConn = $connection;
        $payload = is_array($frame['payload'] ?? null) ? $frame['payload'] : [];
        $id = (string) ($frame['id'] ?? '');

        // 同步预校验：未连接 / 已在追踪 / binlogFile 缺失 → 直接以 JSON 错误帧返回（此时尚未进入 SSE 流）
        $early = $this->validateDump($id, $payload);
        if ($early instanceof Response) {
            return $early;
        }

        // 进入 SSE 流：返回带 text/event-stream 的 Response。
        // 注意：不要加 Transfer-Encoding: chunked —— 否则 Workerman 会对响应做
        // chunked 编码（结尾追加 0\r\n\r\n），后续裸 data 帧在流结束后到达，
        // 浏览器报 ERR_INVALID_CHUNKED_ENCODING。
        // Workerman 的 Response::__toString 对 text/event-stream 只输出
        // headers + 空行（无 Content-Length、无 chunk 尾巴），HTTP/1.1 下
        // App::send 走 $connection->send() 保持连接打开，后续裸 data 帧正常推送。
        // CORS 头由 app\middleware\Cors 自动添加，此处不再重复（避免重复头）。
        $this->sse = true;
        // 关键：要等 webman 把 SSE 响应头 flush 出去之后，才能开始写 data 帧，
        // 否则 data 会先于 HTTP 头被写进 socket，客户端收不到合法流。
        // 用一次性 Timer 延到下一个事件循环 tick（响应头已写出）再启动 worker 并推首帧。
        $self = $this;
        Timer::add(0, function () use ($self, $id, $payload): void {
            $self->handleBinlogDump($id, $payload);
        }, null, false);
        return new Response(200, [
            // 标准 SSE 头。Transfer-Encoding: chunked 让 webman 的 App::send()
            // 走 $connection->send($response)（保持连接打开），后续每帧用
            // new Chunk("data: ...\n\n") 发送，浏览器按 chunked 流解析。
            // 注意 Content-Type 必须用精确的 'text/event-stream' 才能命中
            // Workerman Response::__toString 的 SSE 分支（只输出 headers+空行，不加 Content-Length）。
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Transfer-Encoding' => 'chunked',
        ], '');
    }

    /** 进入 SSE 流前的同步预校验；返回 Response 表示校验失败（直接以 JSON 错误帧回写） */
    private function validateDump(string $id, array $payload): ?Response
    {
        if (!$this->connected || $this->mysqlHost === '') {
            return $this->respondOnce($id, 'error', ['code' => AgentConstants::PROXY_NOT_READY, 'message' => '尚未连接 MySQL']);
        }
        if ($this->dumping) {
            return $this->respondOnce($id, 'error', ['code' => AgentConstants::INVALID_PARAM, 'message' => '已在追踪中']);
        }
        $fileName = (string) ($payload['binlogFile'] ?? $this->binlogFile);
        if ($fileName === '') {
            return $this->respondOnce($id, 'error', ['code' => AgentConstants::INVALID_PARAM, 'message' => 'binlogFile 不能为空']);
        }
        return null;
    }

    /** POST /query — 执行只读查询，回传 query-result 或 error（Krowinski 同步，直接返回响应） */
    public function onQuery(TcpConnection $connection, array $frame): ?Response
    {
        $this->conn = $connection;
        $this->sse = false;
        $payload = is_array($frame['payload'] ?? null) ? $frame['payload'] : [];
        $id = (string) ($frame['id'] ?? '');
        return $this->handleQuery($id, $payload, $connection);
    }

    /** POST /close — 销毁会话，关闭 MySQL/dump，确认关闭 */
    public function onCloseRequest(TcpConnection $connection, array $frame): Response
    {
        $this->conn = $connection;
        $this->sse = false;
        $this->handleClose();
        return new Response(200, $this->withCors(['Content-Type' => 'application/json; charset=utf-8']), json_encode([
            'ok' => true,
            'session' => $this->sessionToken,
        ], JSON_UNESCAPED_SLASHES));
    }

    /** 清理定时器与 MySQL 连接（会话销毁时） */
    public function destroy(): void
    {
        if ($this->heartbeatTimer !== false) {
            Timer::del($this->heartbeatTimer);
            $this->heartbeatTimer = false;
        }
        $this->teardownMysql();
        $this->teardownQuery();
        $this->teardownDumpWorker();
        $this->connected = false;
        $this->dumping = false;
    }

    // ─── 消息处理 ─────────────────────────────────────────

    private function handleConnect(string $id, array $payload): Response
    {
        // 连接已建立或正在建立中（覆盖进行中的 connect），拒绝重复发起
        if ($this->mysql !== null) {
            return $this->respondOnce($id, 'error', ['code' => AgentConstants::INVALID_PARAM, 'message' => '连接已建立或正在建立中，请先 close']);
        }
        $host = (string) ($payload['host'] ?? '');
        $port = (int) ($payload['port'] ?? 3306);
        $user = (string) ($payload['user'] ?? '');
        $password = (string) ($payload['password'] ?? '');
        $database = (string) ($payload['database'] ?? '');
        $timeout = (int) ($payload['connectTimeoutMs'] ?? AgentConstants::CONNECT_TIMEOUT_MS);
        $clientId = (int) ($payload['serverId'] ?? 0);

        if ($host === '') {
            return $this->respondOnce($id, 'error', ['code' => AgentConstants::INVALID_PARAM, 'message' => 'host 不能为空']);
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

        $mysql = new KrowinskiQueryAdapter();
        $this->mysql = $mysql;

        $ok = $mysql->connect(
            $host,
            $port,
            $user,
            $password,
            $database,
            max(1, (int) ceil($timeout / 1000))
        );
        if ($ok) {
            $self = $this;
            return $self->onMysqlConnected($id);
        }
        $self = $this;
        $resp = $self->respondOnce($id, 'error', ['code' => 1001, 'message' => 'MySQL 认证失败（krowinski 适配器无法建立 PDO 连接）']);
        $self->teardownMysql();
        return $resp;
    }

    private function onMysqlConnected(string $id): Response
    {
        if ($this->mysql === null) {
            return $this->respondOnce($id, 'error', ['code' => 1001, 'message' => 'MySQL 连接丢失']);
        }
        $this->connected = true;
        $meta = new MetaGatherer($this->mysql, $this->serverId);
        $self = $this;
        return $meta->gather(function (array $metaData) use ($self, $id): Response {
            if (($metaData['hasBinlog'] ?? false) === true) {
                $self->binlogFile = (string) ($metaData['binlogFile'] ?? '');
            }
            // 注册会话，生成 token 并回传（后续 dump/query/close 凭此关联）
            $self->sessionToken = SessionManager::create($self);
            $metaData['session'] = $self->sessionToken;
            return $self->respondOnce($id, 'connected', $metaData);
        });
    }

    /** @return Response|null 返回 Response 表示提前错误（控制器直接返回该响应，不进入 SSE 流）；
     *          流式已在 onDump 内通过一次性 Timer 异步启动（此时已处于 SSE 流，
     *          错误以 SSE error 帧回写并关闭连接，不再返回 Response） */
    private function handleBinlogDump(string $id, array $payload): void
    {
        // 预校验已在 onDump 的 validateDump 完成；此处仅启动 worker。
        // 若启动失败，以 SSE error 帧回写后关闭流。
        $fileName = (string) ($payload['binlogFile'] ?? $this->binlogFile);
        $filePos = (int) ($payload['binlogPos'] ?? 4);

        // 时间窗口（前端传 epoch 毫秒，转秒给 worker；0 = 不限）
        $startTs = (int) ($payload['startMs'] ?? 0) > 0 ? intdiv((int) $payload['startMs'], 1000) : 0;
        $endTs = (int) ($payload['endMs'] ?? 0) > 0 ? intdiv((int) $payload['endMs'], 1000) : 0;

        $this->dumping = true;
        $worker = __DIR__ . '/../../bin/krowinski_dump.php';
        if (!is_file($worker)) {
            $this->dumping = false;
            $this->sendError($id, AgentConstants::INTERNAL_ERROR, 'krowinski 解析脚本缺失: bin/krowinski_dump.php');
            $this->closeSse();
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
        $runtime = dirname(dirname(__DIR__)) . '/runtime';
        if (!is_dir($runtime)) {
            @mkdir($runtime, 0777, true);
        }
        $outFile = $runtime . '/krowinski_' . $this->serverId . '_' . bin2hex(random_bytes(4)) . '.out';
        $errFile = $runtime . '/krowinski_' . $this->serverId . '_' . bin2hex(random_bytes(4)) . '.err';
        $proc = proc_open(
            $cmd,
            [1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']],
            $pipes,
            dirname(dirname(__DIR__)),
            $env,
            ['bypass_shell' => true]
        );
        if (!is_resource($proc)) {
            $this->dumping = false;
            $this->sendError($id, AgentConstants::INTERNAL_ERROR, '无法启动 krowinski 解析进程');
            $this->closeSse();
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

    /** SSE 流异常时关闭连接（此时不能再返回 Response，只能主动 close） */
    private function closeSse(): void
    {
        if ($this->sse && $this->conn !== null) {
            $this->conn->send(new Chunk(''));
            $this->conn->close();
        }
        $this->sseConn = null;
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
                    $errTail = substr($errTail, -500);
                }
                $this->sendError('', AgentConstants::INTERNAL_ERROR, 'binlog 解析进程异常退出 code=' . $exitCode . ($errTail !== '' ? ': ' . $errTail : ''));
            }
            $this->teardownDumpWorker();
        }
    }

    /** 结束 krowinski 子进程（kill + 停轮询 + 清理临时文件），并关闭 SSE 连接 */
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
        if ($this->sse && $this->conn !== null) {
            // chunked 流结束：先发空 Chunk（0\r\n\r\n）标识流结束，再关闭连接
            $this->conn->send(new Chunk(''));
            $this->conn->close();
        }
        $this->sseConn = null;
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

    private function handleQuery(string $id, array $payload, TcpConnection $connection): ?Response
    {
        if (!$this->connected || $this->mysqlHost === '') {
            return $this->respondOnce($id, 'error', ['code' => AgentConstants::PROXY_NOT_READY, 'message' => '尚未连接 MySQL']);
        }
        $sql = (string) ($payload['sql'] ?? '');
        $trimmed = trim($sql);
        if (!preg_match('/^\s*(SELECT|SHOW)\b.*$/i', $trimmed)) {
            return $this->respondOnce($id, 'error', ['code' => AgentConstants::PROTOCOL_ERROR, 'message' => '仅允许只读查询']);
        }
        // 帧内 database 优先（前端 useSchemaMeta 查表会传目标库），缺省沿用连接库
        $database = trim((string) ($payload['database'] ?? ''));
        // 单 MySQL 连接一次只能执行一条查询：并发帧（如 React StrictMode 双挂载连发
        // 库列表）排队串行执行，不再回错误帧——错误帧会令前端下拉被禁用。
        // Krowinski 适配器为同步，队列实际串行执行，每个请求同步返回各自的响应。
        $this->queryQueue[] = ['id' => $id, 'sql' => $sql, 'database' => $database, 'conn' => $connection];
        return $this->drainQueryQueue();
    }

    /** 队列非空且查询连接空闲（或需建连）时执行队首；busy/建连中则等完成回调再 drain。
     *  @return Response|null 同步执行时返回该查询的响应（供控制器直接返回，避免 webman 双发） */
    private function drainQueryQueue(): ?Response
    {
        if ($this->queryQueue === []) {
            return null;
        }
        if ($this->queryConnecting) {
            return null;
        }
        $client = $this->queryMysql;
        if ($client !== null && !$client->isConnected()) {
            // 适配器连接已断：清理后重建
            $client->close();
            $this->queryMysql = null;
        }
        // peek 队首，不提前出队：建连是异步的，出队须等到真正派发（直接执行/建连完成）时，
        // 否则首条查询会在建连期间丢失（连接就绪后队列已空，永不执行）
        return $this->execQuery($this->queryQueue[0]);
    }

    /** 执行单条查询：连接空闲且库匹配直接发，否则（重新）建连后发。返回同步生成的响应 */
    private function execQuery(array $item): ?Response
    {
        $id = (string) $item['id'];
        $sql = (string) $item['sql'];
        $db = $item['database'] !== '' ? (string) $item['database'] : $this->mysqlDatabase;
        /** @var TcpConnection $conn */
        $conn = $item['conn'];
        $client = $this->queryMysql;
        // Krowinski 适配器：同步建连，无 isAlive/isConnected/getCurrentDatabase 区分，
        // 直接检查 isConnected()；库匹配简化处理（每次重建连接时传入目标库）
        if ($client !== null && $client->isConnected()) {
            array_shift($this->queryQueue);
            $self = $this;
            return $client->query(
                $sql,
                function (array $result) use ($self, $id): Response {
                    $colOut = [];
                    foreach (($result['columns'] ?? []) as $col) {
                        $colOut[] = [
                            'name' => (string) ($col['name'] ?? ''),
                            'type' => (string) ($col['type'] ?? ''),
                        ];
                    }
                    return $self->respondOnce($id, 'query-result', [
                        'columns' => $colOut,
                        'rows' => $result['rows'] ?? [],
                    ]);
                },
                function (int $code, string $message) use ($self, $id): Response {
                    return $self->respondOnce($id, 'error', ['code' => $code, 'message' => $message]);
                }
            );
        }
        if ($client !== null) {
            // 库不匹配或连接不可用：关旧建新（重认证）
            $client->close();
            $this->queryMysql = null;
        }
        // 使用 krowinski 适配器同步建连（替代 AsyncClient 异步建连）
        $self = $this;
        $this->queryConnecting = true;
        $fresh = new KrowinskiQueryAdapter();
        $timeout = max(1, (int) ceil(($this->mysqlTimeoutMs > 0 ? $this->mysqlTimeoutMs : AgentConstants::CONNECT_TIMEOUT_MS) / 1000));
        $ok = $fresh->connect(
            $this->mysqlHost,
            $this->mysqlPort,
            $this->mysqlUser,
            $this->mysqlPassword,
            $db,
            $timeout
        );
        $this->queryMysql = $fresh;
        if ($ok) {
            $this->queryConnecting = false;
            array_shift($this->queryQueue);
            return $fresh->query(
                $sql,
                function (array $result) use ($self, $id): Response {
                    $colOut = [];
                    foreach (($result['columns'] ?? []) as $col) {
                        $colOut[] = [
                            'name' => (string) ($col['name'] ?? ''),
                            'type' => (string) ($col['type'] ?? ''),
                        ];
                    }
                    return $self->respondOnce($id, 'query-result', [
                        'columns' => $colOut,
                        'rows' => $result['rows'] ?? [],
                    ]);
                },
                function (int $code, string $message) use ($self, $id): Response {
                    return $self->respondOnce($id, 'error', ['code' => $code, 'message' => $message]);
                }
            );
        }
        // 建连失败
        $this->queryConnecting = false;
        array_shift($this->queryQueue);
        $resp = $self->respondOnce($id, 'error', ['code' => 1002, 'message' => '查询连接失败（krowinski 适配器）'], $conn);
        $self->drainQueryQueue();
        return $resp;
    }

    private function handleClose(): void
    {
        $this->dumping = false;
        $this->connected = false;
        $this->teardownMysql();
        $this->teardownQuery();
        $this->teardownDumpWorker();
        if ($this->sessionToken !== '') {
            SessionManager::remove($this->sessionToken);
            $this->sessionToken = '';
        }
    }



    // ─── 发送辅助 ─────────────────────────────────────────

    private function sendError(string $id, int $code, string $message): void
    {
        $this->sendFrame($id, 'error', ['code' => $code, 'message' => $message]);
    }

    private function sendHeartbeat(): void
    {
        // 仅 SSE（dump）期间推送心跳；HTTP 短连接无需心跳
        if (!$this->sse) {
            return;
        }
        $this->sendFrame('', 'heartbeat', [
            'ts' => self::now(),
            'binlogPos' => null,
        ]);
    }

    /**
     * 统一帧发送（SSE 模式）：
     * 用 new Chunk("data: {json}\n\n") 包裹，走 HTTP chunked 编码，
     * 浏览器按 chunked SSE 流解析。非 SSE（connect/query）不走此方法，由 respondOnce 回单帧。
     */
    private function sendFrame(string $id, string $type, array $payload): void
    {
        $frame = [
            'v' => AgentConstants::PROTOCOL_VERSION,
            'id' => $id,
            'type' => $type,
            'ts' => self::now(),
            'payload' => $payload,
        ];
        $json = json_encode($frame, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        if ($this->sse && $this->conn !== null) {
            $this->conn->send(new Chunk("data: " . $json . "\n\n"));
        }
    }

    /** CORS 响应头由全局中间件 app\middleware\Cors 统一添加，此处不再重复（避免重复头） */
    private function withCors(array $headers): array
    {
        return $headers;
    }

    /** 普通请求（connect/query）的单帧 HTTP 响应；返回 Response 供控制器直接返回 */
    private function respondOnce(string $id, string $type, array $payload, ?TcpConnection $conn = null): Response
    {
        $frame = [
            'v' => AgentConstants::PROTOCOL_VERSION,
            'id' => $id,
            'type' => $type,
            'ts' => self::now(),
            'payload' => $payload,
        ];
        $json = json_encode($frame, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{"v":2,"id":"' . $id . '","type":"error","ts":' . self::now() . ',"payload":{"code":1013,"message":"encode error"}}';
        }
        return new Response(200, $this->withCors([
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]), $json);
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
