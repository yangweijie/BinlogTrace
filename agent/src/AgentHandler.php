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
 * 与 Server 解耦：发送通过注入的回调完成（sseWriter 写 SSE 行、responder 写单帧 JSON）。
 * dump 由 C++ 层（mysqlbox_dump_start，COM_BINLOG_DUMP + 行事件完整类型还原）在后台线程
 * 抓取并解析，Server 事件循环按 tick 调用 pollDump() 拉取已解析事件并转 binlog-change 帧，
 * 保持事件驱动、不阻塞、不依赖子进程/管道。
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

    /** C++ DumpSession 句柄（mysqlbox_dump_start 返回） */
    private $dumpHandle = null;
    /** 行缓冲（保留字段，便于未来扩展） */
    private string $dumpBuffer = '';
    /** SSE 写出回调（注入） */
    private ?SseWriter $sseWriter = null;
    /** 单帧响应回调（注入） */
    private ?JsonResponder $responder = null;

    public function __construct()
    {
        $this->serverId = random_int(1, 2147483647);
    }

    // ─── 回调注入（由 Server 设置） ────────────────────────

    public function setSseWriter(SseWriter $fn): void
    {
        $this->sseWriter = $fn;
    }

    public function setResponder(JsonResponder $fn): void
    {
        $this->responder = $fn;
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
            $this->respond($id, 'error', ['code' => AgentConstants::AUTH_FAILED, 'message' => 'MySQL 连接失败: ' . $e->getMessage()]);
            return;
        }
        $this->conn = $conn;
        $this->connected = true;

        $meta = (new MetaGatherer($conn, $this->serverId))->gather();
        if (($meta['hasBinlog'] ?? false) === true) {
            $this->binlogFile = (string) ($meta['binlogFile'] ?? '');
        }
        $this->sessionToken = SessionManager::create($this);
        $meta['session'] = $this->sessionToken;
        $this->respond($id, 'connected', $meta);
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
        $endTs = (int) ($payload['endMs'] ?? 0) > 0 ? intdiv((int) $payload['endTs'], 1000) : 0;

        // 启动 C++ 后台线程 dump（COM_BINLOG_DUMP + 行事件完整类型还原）
        $handle = @mysqlbox_dump_start(
            $this->mysqlHost,
            $this->mysqlPort,
            $this->mysqlUser,
            $this->mysqlPassword,
            $fileName,
            $filePos,
            $this->serverId
        );
        if ($handle === false || $handle === null) {
            $this->sse($id, 'error', [
                'code' => AgentConstants::INTERNAL_ERROR,
                'message' => 'binlog dump 启动失败：mysqlbox_dump_start 返回空（请检查 MySQL 复制权限与 binlog 位点）',
            ]);
            return;
        }
        $this->dumpHandle = $handle;
        $this->dumping = true;
        $this->sse($id, 'dump-started', [
            'binlogFile' => $fileName,
            'binlogPos' => $filePos,
            'serverId' => $this->serverId,
        ]);
        return;
    }

    /**
     * 由 Server 事件循环按 tick 调用，从 C++ 线程安全队列拉取已解析的行变更并推 SSE。
     * 返回 true 表示 dump 已结束（出错或正常结束），调用方应关闭 SSE 连接。
     */
    public function pollDump(): bool
    {
        if ($this->dumpHandle === null) {
            return true;
        }
        $res = @mysqlbox_dump_poll($this->dumpHandle);
        if (!is_array($res)) {
            return true;
        }
        $events = $res['events'] ?? [];
        foreach ($events as $ev) {
            $this->sse('', 'binlog-change', [
                'schema' => (string) ($ev['schema'] ?? ''),
                'table' => (string) ($ev['table'] ?? ''),
                'op' => (string) ($ev['op'] ?? ''),
                'columns' => (array) ($ev['columns'] ?? []),
                'before' => (array) ($ev['before'] ?? []),
                'after' => (array) ($ev['after'] ?? []),
                'binlogPos' => (int) ($ev['logPos'] ?? 0),
            ]);
        }
        $err = $res['error'] ?? null;
        $finished = ($res['finished'] ?? false) === true;
        if ($err !== null && $err !== '') {
            $this->sse('', 'error', ['code' => AgentConstants::INTERNAL_ERROR, 'message' => 'binlog dump 异常: ' . $err]);
            $this->teardownDump();
            return true;
        }
        if ($finished) {
            $this->sse('', 'binlog-end', ['exitCode' => 0]);
            $this->teardownDump();
            return true;
        }
        return false;
    }

    /** 心跳（仅 SSE 期间由 Server 定时器推送） */
    public function sendHeartbeat(): void
    {
        if (!$this->dumping) {
            return;
        }
        $this->sse('', 'heartbeat', ['ts' => Frame::now(), 'binlogPos' => null]);
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
        if ($this->dumpHandle !== null) {
            @mysqlbox_dump_stop($this->dumpHandle);
            $this->dumpHandle = null;
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
        if ($this->sseWriter === null) {
            return;
        }
        $this->sseWriter->write(Frame::sse($id, $type, $payload));
    }

    private function respond(string $id, string $type, array $payload): void
    {
        $this->respondOnceJson(Frame::build($id, $type, $payload));
    }

    private function respondOnceJson(string $json): void
    {
        if ($this->responder === null) {
            return;
        }
        $this->responder->respondJson($json);
    }
}
