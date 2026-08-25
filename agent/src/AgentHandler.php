<?php

declare(strict_types=1);

namespace DmsAgent;

use DmsAgent\Mysql\PdoConnection;

/**
 * AgentHandler — 无连接 HTTP 模式下的会话全生命周期（协议 v2）
 *
 * 路由：
 *   POST /connect  → handleConnect  （请求-响应，回 connected 或 error）
 *   POST /dump     → handleDump     （SSE 长流：dump-started → binlog-change/heartbeat → binlog-end）
 *   POST /query    → handleQuery    （请求-响应，回 query-result 或 error）
 *   POST /close    → handleClose    （销毁会话）
 *
 * 与 Server 解耦：发送通过注入的 ClientConn 通道完成（writeSse 写 SSE 行、respond 写单帧 JSON），
 * 子进程 stdout 由 Server 的事件循环读取并调用 onDumpLine()，保持事件驱动、不阻塞。
 */
final class AgentHandler
{
    private ?PdoConnection $conn = null;
    private ?PdoConnection $queryConn = null;
    private bool $connected = false;
    private bool $dumping = false;
    private string $sessionToken = '';

    private string $mysqlHost = '';
    private int $mysqlPort = 3306;
    private string $mysqlUser = '';
    private string $mysqlPassword = '';
    private string $mysqlDatabase = '';
    private int $mysqlTimeoutMs = AgentConstants::CONNECT_TIMEOUT_MS;
    private int $serverId;
    private string $binlogFile = '';

    /** dump 子进程资源（proc_open 返回） */
    private $dumpProc = null;
    /** 子进程 stdout 管道（非阻塞，由 Server 事件循环 select 读取） */
    private $dumpPipe = null;
    /** 子进程 stderr 管道 */
    private $dumpErrPipe = null;
    /** 行缓冲（JSON 行可能跨多次读取） */
    private string $dumpBuffer = '';
    /** 发送通道（注入，替代不可编译的闭包回调） */
    private ?ClientConn $client = null;

    public function __construct()
    {
        $this->serverId = random_int(1, 2147483647);
    }

    // ─── 通道注入（由 Router/Server 设置） ──────────────────

    public function setClient(ClientConn $c): void
    {
        $this->client = $c;
    }

    public function getSessionToken(): string
    {
        return $this->sessionToken;
    }

    // ─── connect（测试连接 + 元数据） ──────────────────────

    public function handleConnect(string $id, array $payload): void
    {
        if ($this->conn !== null) {
            $this->respond($id, 'error', ['code' => AgentConstants::INVALID_PARAM, 'message' => '连接已建立或正在建立中，请先 close']);
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
            $this->respond($id, 'error', ['code' => AgentConstants::INVALID_PARAM, 'message' => 'host 不能为空']);
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

        $conn = new PdoConnection();
        try {
            $conn->connect($host, $port, $user, $password, $database, max(1, (int) ceil($timeout / 1000)));
        } catch (\Throwable $e) {
            $this->respond($id, 'error', [
                'code' => AgentConstants::AUTH_FAILED,
                'message' => 'MySQL 连接失败: ' . $e->getMessage(),
                'diagnostic' => $this->diagnosticInfo(),
            ]);
            return;
        }
        $this->conn = $conn;
        $this->connected = true;

        $meta = (new MetaGatherer($conn->pdo(), $this->serverId))->gather();
        if (($meta['hasBinlog'] ?? false) === true) {
            $this->binlogFile = (string) ($meta['binlogFile'] ?? '');
        }
        $this->sessionToken = SessionManager::create($this);
        $meta['session'] = $this->sessionToken;
        $this->respond($id, 'connected', $meta);
    }

    /**
     * 连接失败时的运行时诊断（分字段返回，便于前端/调试阅读）：
     * 扩展加载情况、PHP 构建参数、php.ini 与 ext 目录，
     * 用于确认 pdo_mysql 驱动是否真正编入当前运行时。
     *
     * @return array<string, mixed>
     */
    private function diagnosticInfo(): array
    {
        $ini = php_ini_loaded_file();
        if ($ini === false || $ini === '') {
            $ini = null;
        }
        $extDir = ini_get('extension_dir');
        if ($extDir === false || $extDir === '') {
            $extDir = null;
        }

        $loaded = get_loaded_extensions();
        sort($loaded);

        return [
            'pdo' => extension_loaded('pdo'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'mysqli' => extension_loaded('mysqli'),
            'mysqlnd' => extension_loaded('mysqlnd'),
            'php_ini' => $ini,
            'extension_dir' => $extDir,
            'php_version' => PHP_VERSION,
            'zts' => defined('PHP_ZTS') ? (bool) PHP_ZTS : false,
            'debug' => defined('PHP_DEBUG') ? (bool) PHP_DEBUG : false,
            'arch' => PHP_INT_SIZE === 8 ? 'x86_64' : 'x86',
            'sapi' => PHP_SAPI,
            'module' => 'typephp_binlog_agent',
            'extensions' => $loaded,
        ];
    }

    // ─── query（只读） ─────────────────────────────────────

    public function handleQuery(string $id, array $payload): void
    {
        if (!$this->connected || $this->mysqlHost === '') {
            $this->respond($id, 'error', ['code' => AgentConstants::PROXY_NOT_READY, 'message' => '尚未连接 MySQL']);
            return;
        }
        $sql = (string) ($payload['sql'] ?? '');
        if (!preg_match('/^\s*(SELECT|SHOW|DESC|DESCRIBE|EXPLAIN)\b.*$/i', trim($sql))) {
            $this->respond($id, 'error', ['code' => AgentConstants::PROTOCOL_ERROR, 'message' => '仅允许只读查询']);
            return;
        }
        $database = trim((string) ($payload['database'] ?? ''));
        $db = $database !== '' ? $database : $this->mysqlDatabase;

        if ($this->queryConn === null || !$this->queryConn->isConnected() || $this->queryConn->database() !== $db) {
            try {
                $qc = new PdoConnection();
                $qc->connect($this->mysqlHost, $this->mysqlPort, $this->mysqlUser, $this->mysqlPassword, $db, max(1, (int) ceil($this->mysqlTimeoutMs / 1000)));
                $this->queryConn = $qc;
            } catch (\Throwable $e) {
                $this->respond($id, 'error', ['code' => AgentConstants::NETWORK_UNREACHABLE, 'message' => '查询连接失败: ' . $e->getMessage()]);
                return;
            }
        }

        try {
            $result = $this->queryConn->query($sql);
            $columns = [];
            foreach ($result['columns'] as $col) {
                $columns[] = ['name' => (string) ($col['name'] ?? ''), 'type' => (string) ($col['type'] ?? '')];
            }
            $this->respond($id, 'query-result', ['columns' => $columns, 'rows' => $result['rows']]);
        } catch (\Throwable $e) {
            $this->respond($id, 'error', ['code' => AgentConstants::PARSE_ERROR, 'message' => '查询执行失败: ' . $e->getMessage()]);
        }
    }

    // ─── dump（解析 binlog，SSE 长流） ─────────────────────

    public function handleDump(string $id, array $payload): void
    {
        if (!$this->connected || $this->mysqlHost === '') {
            $this->sse($id, 'error', ['code' => AgentConstants::PROXY_NOT_READY, 'message' => '尚未连接 MySQL']);
            return;
        }
        if ($this->dumping) {
            $this->sse($id, 'error', ['code' => AgentConstants::INVALID_PARAM, 'message' => '已在追踪中']);
            return;
        }
        $fileName = (string) ($payload['binlogFile'] ?? $this->binlogFile);
        $filePos = (int) ($payload['binlogPos'] ?? 4);
        if ($fileName === '') {
            $this->sse($id, 'error', ['code' => AgentConstants::INVALID_PARAM, 'message' => 'binlogFile 不能为空']);
            return;
        }
        $startTs = (int) ($payload['startMs'] ?? 0) > 0 ? intdiv((int) $payload['startMs'], 1000) : 0;
        $endTs = (int) ($payload['endMs'] ?? 0) > 0 ? intdiv((int) $payload['endMs'], 1000) : 0;

        $worker = __DIR__ . '/../bin/mysqlbinlog_dump.php';
        if (!is_file($worker)) {
            $this->sse($id, 'error', ['code' => AgentConstants::INTERNAL_ERROR, 'message' => 'krowinski 解析脚本缺失']);
            return;
        }

        $env = array_merge(getenv(), ['DMS_MYSQL_PASSWORD' => $this->mysqlPassword]);
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

        $proc = proc_open(
            $cmd,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            __DIR__ . '/..',
            $env,
            ['bypass_shell' => true]
        );
        if (!is_resource($proc)) {
            $this->sse($id, 'error', ['code' => AgentConstants::INTERNAL_ERROR, 'message' => '无法启动 krowinski 解析进程']);
            return;
        }
        // 非阻塞管道：由 Server 事件循环 select 读取，不阻塞主循环
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->dumpProc = $proc;
        $this->dumpPipe = $pipes[1];
        $this->dumpErrPipe = $pipes[2];
        $this->dumpBuffer = '';
        $this->dumping = true;

        // 通知 Server 把管道加入事件循环 select
        if ($this->client !== null) {
            $this->client->registerDumpPipe($this->dumpPipe);
        }

        $this->sse('', 'dump-started', ['binlogFile' => $fileName, 'binlogPos' => $filePos]);
    }

    /**
     * 由 Server 事件循环在 dump 子进程 stdout 可读时调用，解析 JSON 行并转 binlog-change 帧。
     * 返回 'running' | 'ended' | 'error'。
     */
    public function onDumpReadable(): string
    {
        if ($this->dumpPipe === null) {
            return 'ended';
        }
        $chunk = @fread($this->dumpPipe, 65536);
        if ($chunk === '' || $chunk === false) {
            // 管道 EOF 或暂无可读：检查进程是否仍存活
            if ($this->dumpProc !== null) {
                $st = proc_get_status($this->dumpProc);
                if (!$st['running']) {
                    $this->finishDump((int) $st['exitcode']);
                    return 'ended';
                }
            }
            return 'running';
        }
        $this->dumpBuffer .= $chunk;
        while (($nl = strpos($this->dumpBuffer, "\n")) !== false) {
            $line = trim(substr($this->dumpBuffer, 0, $nl));
            $this->dumpBuffer = substr($this->dumpBuffer, $nl + 1);
            if ($line === '') {
                continue;
            }
            $obj = json_decode($line, true);
            if (!is_array($obj) || ($obj['type'] ?? '') !== 'change') {
                // heartbeat 等类型忽略（前端不消费）
                continue;
            }
            $this->sse('', 'binlog-change', [
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
        // 再次确认进程是否已退出（子进程可能已写完并退出）
        if ($this->dumpProc !== null) {
            $st = proc_get_status($this->dumpProc);
            if (!$st['running']) {
                $this->finishDump((int) $st['exitcode']);
                return 'ended';
            }
        }
        return 'running';
    }

    private function finishDump(int $exitCode): void
    {
        if ($exitCode === 0) {
            $this->sse('', 'binlog-end', ['exitCode' => 0]);
        } else {
            $err = '';
            if ($this->dumpErrPipe !== null && is_resource($this->dumpErrPipe)) {
                $err = trim((string) @stream_get_contents($this->dumpErrPipe));
                $err = substr($err, -300);
            }
            $this->sse('', 'error', ['code' => AgentConstants::INTERNAL_ERROR, 'message' => 'binlog 解析进程异常退出 code=' . $exitCode . ($err !== '' ? ': ' . $err : '')]);
        }
        $this->teardownDump();
    }

    /** 心跳（仅 SSE 期间由 Server 定时器推送） */
    public function sendHeartbeat(): void
    {
        if (!$this->dumping) {
            return;
        }
        $this->sse('', 'heartbeat', ['ts' => Frame::now(), 'binlogPos' => null]);
    }

    public function dumpPipe()
    {
        return $this->dumpPipe;
    }

    public function dumpErrPipe()
    {
        return $this->dumpErrPipe;
    }

    public function isDumping(): bool
    {
        return $this->dumping;
    }

    // ─── close ─────────────────────────────────────────────

    public function handleClose(): void
    {
        $this->teardownDump();
        $this->teardownConn();
        if ($this->sessionToken !== '') {
            SessionManager::remove($this->sessionToken);
            $this->sessionToken = '';
        }
        $this->connected = false;
        $this->dumping = false;
        $this->respondOnceJson(json_encode([
            'ok' => true,
            'session' => $this->sessionToken,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function teardownDump(): void
    {
        if ($this->dumpProc !== null) {
            $st = proc_get_status($this->dumpProc);
            if ($st['running']) {
                proc_terminate($this->dumpProc);
            }
            proc_close($this->dumpProc);
            $this->dumpProc = null;
        }
        if ($this->dumpPipe !== null && is_resource($this->dumpPipe)) {
            fclose($this->dumpPipe);
            $this->dumpPipe = null;
        }
        if ($this->dumpErrPipe !== null && is_resource($this->dumpErrPipe)) {
            fclose($this->dumpErrPipe);
            $this->dumpErrPipe = null;
        }
        $this->dumpBuffer = '';
        $this->dumping = false;
    }

    private function teardownConn(): void
    {
        if ($this->conn !== null) {
            $this->conn->close();
            $this->conn = null;
        }
        if ($this->queryConn !== null) {
            $this->queryConn->close();
            $this->queryConn = null;
        }
    }

    // ─── 发送辅助 ──────────────────────────────────────────

    private function sse(string $id, string $type, array $payload): void
    {
        if ($this->client === null) {
            return;
        }
        $this->client->writeSse(Frame::sse($id, $type, $payload));
    }

    private function respond(string $id, string $type, array $payload): void
    {
        $this->respondOnceJson(Frame::build($id, $type, $payload));
    }

    private function respondOnceJson(string $json): void
    {
        if ($this->client === null) {
            return;
        }
        $this->client->respond($json);
    }
}
