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
    // 注意：属性默认值不能引用其他类的常量（TypePHP stub 生成器限制），在构造器中初始化
    private int $mysqlTimeoutMs;
    private int $serverId;
    private string $binlogFile = '';

    /** dump 子进程资源（proc_open 返回） */
    private $dumpProc = null;
    /**
     * 子进程 stdout 重定向文件（而非管道）。
     * Windows 下 proc_open 管道无法设为非阻塞（stream_set_blocking 返回 false），
     * 事件循环里任何 fread 都可能阻塞十几秒直到子进程写出下一行 —— 整个服务被拖死。
     * 旧 webman 版因此用文件重定向 + 增量轮询，此处沿用同一方案。
     */
    private string $dumpOutFile = '';
    private string $dumpErrFile = '';
    /** stdout/stderr 文件已读偏移（增量轮询） */
    private int $dumpOffset = 0;
    private int $dumpErrOffset = 0;
    /** 行缓冲（JSON 行可能跨多次读取） */
    private string $dumpBuffer = '';
    /** 子进程 stderr 累积（异常退出时随 error 帧回传，避免只剩退出码） */
    private string $dumpErrBuf = '';
    /** 正在 dump 的 handler 注册表（Server 事件循环每个 tick 轮询其输出文件） */
    private static array $activeDump = [];
    /** 已退出但尚未 proc_close 的子进程（避免同步 proc_close 阻塞事件循环） */
    private static array $reapQueue = [];
    /** 发送通道（注入，替代不可编译的闭包回调） */
    private ?ClientConn $client = null;

    public function __construct()
    {
        $this->serverId = random_int(1, 2147483647);
        $this->mysqlTimeoutMs = AgentConstants::CONNECT_TIMEOUT_MS;
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
            $this->failSse($id, AgentConstants::PROXY_NOT_READY, '尚未连接 MySQL');
            return;
        }
        if ($this->dumping) {
            $this->failSse($id, AgentConstants::INVALID_PARAM, '已在追踪中');
            return;
        }
        $fileName = (string) ($payload['binlogFile'] ?? $this->binlogFile);
        $filePos = (int) ($payload['binlogPos'] ?? 4);
        if ($fileName === '') {
            $this->failSse($id, AgentConstants::INVALID_PARAM, 'binlogFile 不能为空');
            return;
        }
        $startTs = (int) ($payload['startMs'] ?? 0) > 0 ? intdiv((int) $payload['startMs'], 1000) : 0;
        $endTs = (int) ($payload['endMs'] ?? 0) > 0 ? intdiv((int) $payload['endMs'], 1000) : 0;
        // 历史追溯语义：结束时间不等待未来时刻。前端表单残留/误选未来 endTs 时，
        // worker 会一直 tail 到该时刻（心跳兜底 time()>endTs+2 迟迟不成立），
        // 表现为进度条转圈不停；收敛到当前时间后，历史追平即快速退出。
        if ($endTs > 0) {
            $endTs = min($endTs, time());
        }

        // 预检失败一律以「错误帧 + 关闭 SSE」返回：此时没有子进程可等，
        // 不关闭会让服务端连接与客户端 fetch 流一直挂着（旧 webman 版有 closeSse）
        if (!function_exists('socket_create') || !function_exists('socket_shutdown')) {
            $this->failSse($id, AgentConstants::INTERNAL_ERROR,
                '当前 PHP 缺少 ext-sockets 扩展，无法解析 binlog（php.ini 启用 extension=php_sockets.dll，并确认 extension_dir 有效）');
            return;
        }

        // dump worker 的两种拉起方式：
        //   embed（编译二进制）：再次拉起自身二进制，走 __dump_worker 隐藏子命令，
        //     子进程经 ZendVM 执行磁盘上的 vendor/ + bin/dump_worker.php（单二进制分发）
        //   cli（php 解释器开发模式）：PHP_BINARY 直接执行磁盘脚本
        $isEmbed = PHP_SAPI === 'embed';
        if ($isEmbed) {
            $workerCheck = Runtime::$binDir . '/bin/dump_worker.php';
        } else {
            $workerCheck = __DIR__ . '/../bin/dump_worker.php';
        }
        if (!is_file($workerCheck)) {
            $this->failSse($id, AgentConstants::INTERNAL_ERROR, 'dump 解析脚本缺失: ' . $workerCheck);
            return;
        }

        $env = array_merge(getenv(), ['DMS_MYSQL_PASSWORD' => $this->mysqlPassword]);
        if ($isEmbed) {
            $cmd = escapeshellarg(Runtime::$binPath) . ' __dump_worker';
        } else {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerCheck);
        }
        $cmd .= ' --host ' . escapeshellarg($this->mysqlHost)
            . ' --port ' . $this->mysqlPort
            . ' --user ' . escapeshellarg($this->mysqlUser)
            . ' --file ' . escapeshellarg($fileName)
            . ' --pos ' . $filePos;
        // 库级过滤：worker 按 schema 精确匹配，缺省用 connect 时选的库；
        // 不传会输出实例上全部库的变更（RDS 共享实例含 mysql./其他业务库）
        $dbFilter = trim((string) ($payload['database'] ?? ''));
        if ($dbFilter === '') {
            $dbFilter = trim($this->mysqlDatabase);
        }
        if ($dbFilter !== '') {
            $cmd .= ' --db ' . escapeshellarg($dbFilter);
        }
        // 表级过滤：前端多选表名（数组或逗号串），'全部'/空 = 不限制
        $tablesIn = $payload['tables'] ?? [];
        $tablesArr = [];
        if (is_string($tablesIn)) {
            $tablesArr = explode(',', $tablesIn);
        } elseif (is_array($tablesIn)) {
            $tablesArr = $tablesIn;
        }
        $tablesList = [];
        foreach ($tablesArr as $one) {
            $name = trim((string) $one);
            if ($name !== '' && $name !== '全部') {
                $tablesList[] = $name;
            }
        }
        if ($tablesList !== []) {
            $cmd .= ' --tables ' . escapeshellarg(implode(',', $tablesList));
        }
        if ($startTs > 0) {
            $cmd .= ' --start-ts ' . $startTs;
        }
        if ($endTs > 0) {
            $cmd .= ' --end-ts ' . $endTs;
        }

        // stdout/stderr 重定向到文件，不用管道：Windows 下 proc_open 管道无法设为非阻塞
        // （stream_set_blocking 直接返回 false），事件循环里 fread 会一直等到子进程
        // 写出下一行 —— 实测可阻塞 14.8 秒，整个服务被拖死（/ping 都无响应）。
        $runtime = Runtime::$binDir . '/runtime';
        if (!is_dir($runtime)) {
            @mkdir($runtime, 0777, true);
        }
        $tag = bin2hex(random_bytes(4));
        $outFile = $runtime . '/dump_' . $this->serverId . '_' . $tag . '.out';
        $errFile = $runtime . '/dump_' . $this->serverId . '_' . $tag . '.err';

        $proc = proc_open(
            $cmd,
            [
                1 => ['file', $outFile, 'w'],
                2 => ['file', $errFile, 'w'],
            ],
            $pipes,
            Runtime::$binDir,
            $env,
            ['bypass_shell' => true]
        );
        if (!is_resource($proc)) {
            $this->failSse($id, AgentConstants::INTERNAL_ERROR, '无法启动 krowinski 解析进程');
            return;
        }

        $this->dumpProc = $proc;
        $this->dumpOutFile = $outFile;
        $this->dumpErrFile = $errFile;
        $this->dumpOffset = 0;
        $this->dumpErrOffset = 0;
        $this->dumpBuffer = '';
        $this->dumpErrBuf = '';
        $this->dumping = true;
        self::$activeDump[$this->sessionToken] = $this;

        $this->sse('', 'dump-started', ['binlogFile' => $fileName, 'binlogPos' => $filePos]);
    }

    /**
     * Server 事件循环每个 tick 调用：轮询所有在跑的 dump 子进程输出文件。
     * 读文件而不是读管道，是为了让读取永远不阻塞（见 dumpOutFile 注释）。
     */
    public static function pollActiveDumps(): void
    {
        foreach (self::$activeDump as $token => $h) {
            if (!$h instanceof self) {
                unset(self::$activeDump[$token]);
                continue;
            }
            $h->pollDump();
        }
    }

    /** @return array<string, AgentHandler> 正在 dump 的 handler（Server 心跳与清理用） */
    public static function activeDumpHandlers(): array
    {
        return self::$activeDump;
    }

    private function pollDump(): void
    {
        if (!$this->dumping) {
            unset(self::$activeDump[$this->sessionToken]);
            return;
        }
        $r = self::readFrom($this->dumpOutFile, $this->dumpOffset);
        $this->dumpOffset = (int) $r[1];
        if ((string) $r[0] !== '') {
            $this->consumeStdout((string) $r[0]);
        }
        $e = self::readFrom($this->dumpErrFile, $this->dumpErrOffset);
        $this->dumpErrOffset = (int) $e[1];
        if ((string) $e[0] !== '') {
            $this->appendDumpErr((string) $e[0]);
        }
        if ($this->dumpProc === null) {
            unset(self::$activeDump[$this->sessionToken]);
            return;
        }
        $st = @proc_get_status($this->dumpProc);
        if (is_array($st) && !($st['running'] ?? true)) {
            // 子进程已退出：再排空一次文件尾，保证最后几行 change 不丢
            $t = self::readFrom($this->dumpOutFile, $this->dumpOffset);
            $this->dumpOffset = (int) $t[1];
            if ((string) $t[0] !== '') {
                $this->consumeStdout((string) $t[0]);
            }
            $te = self::readFrom($this->dumpErrFile, $this->dumpErrOffset);
            $this->dumpErrOffset = (int) $te[1];
            if ((string) $te[0] !== '') {
                $this->appendDumpErr((string) $te[0]);
            }
            $this->finishDump((int) ($st['exitcode'] ?? 0));
        }
    }

    /** 从文件 offset 读到当前末尾，返回 [新增内容, 新偏移] */
    private static function readFrom(string $path, int $offset): array
    {
        if ($path === '') {
            return ['', $offset];
        }
        $size = @filesize($path);
        if ($size === false || $size <= $offset) {
            return ['', $offset];
        }
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return ['', $offset];
        }
        if ($offset > 0) {
            @fseek($h, $offset);
        }
        $data = (string) @stream_get_contents($h);
        @fclose($h);
        return [$data, $offset + strlen($data)];
    }

    /** 解析 stdout 缓冲中的完整 JSON 行，转 binlog-change 帧 */
    private function consumeStdout(string $chunk): void
    {
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
    }

    private function finishDump(int $exitCode): void
    {
        if ($exitCode === 0) {
            $this->sse('', 'binlog-end', ['exitCode' => 0]);
        } elseif ($exitCode === 12) {
            // worker 侧哨兵：binlog_transaction_compression=ON，行事件被封装无法解码
            $this->sse('', 'error', [
                'code' => AgentConstants::TRANSACTION_COMPRESSED,
                'message' => '该实例开启了 binlog 事务压缩（binlog_transaction_compression=ON），行事件无法解析；请关闭后重试：SET GLOBAL binlog_transaction_compression=OFF',
            ]);
        } else {
            $err = substr(trim($this->dumpErrBuf), -300);
            $this->sse('', 'error', ['code' => AgentConstants::INTERNAL_ERROR, 'message' => 'binlog 解析进程异常退出 code=' . $exitCode . ($err !== '' ? ': ' . $err : '')]);
        }
        $this->teardownDump();
        // dump 结束即结束这条 SSE：SSE 无 Content-Length，只有真正断开客户端才能收到流结束
        if ($this->client !== null) {
            $this->client->endSse();
        }
    }

    /** 累积子进程 stderr（诊断用，保留最后 1000 字符） */
    public function appendDumpErr(string $chunk): void
    {
        $this->dumpErrBuf = substr($this->dumpErrBuf . $chunk, -1000);
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

    /** SSE 客户端断开等场景：停掉 dump 子进程并清理管道（会话保留） */
    public function stopDump(): void
    {
        if ($this->dumping) {
            $this->teardownDump();
        }
    }

    // ─── close ─────────────────────────────────────────────

    public function handleClose(): void
    {
        $token = $this->sessionToken;
        $this->destroySession();
        $this->respondOnceJson(json_encode([
            'ok' => true,
            'session' => $token,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * 会话销毁（close 请求 / 被同参数新 connect 替换 / TTL 回收）：只清资源，不发响应。
     * 旧 webman 版有 destroy() 却无人调用，HTTP 模式下没有连接关闭事件，
     * 因此改由 Router（重复 connect 替换）与 Server（空闲 TTL 扫描）两处驱动。
     */
    public function destroySession(): void
    {
        $this->teardownDump();
        $this->teardownConn();
        if ($this->sessionToken !== '') {
            SessionManager::remove($this->sessionToken);
            $this->sessionToken = '';
        }
        $this->connected = false;
        $this->dumping = false;
    }

    private function teardownDump(): void
    {
        unset(self::$activeDump[$this->sessionToken]);
        if ($this->dumpProc !== null) {
            $st = @proc_get_status($this->dumpProc);
            if (is_array($st) && ($st['running'] ?? false)) {
                @proc_terminate($this->dumpProc);
            }
            // 不在这里同步 proc_close：它会等待子进程退出，
            // 在事件循环里就是一次不可控的停顿。交给 reapFinished() 异步回收，
            // 回收完成后再删临时文件（Windows 上文件被子进程持有期间无法删除）。
            self::$reapQueue[] = [
                'proc' => $this->dumpProc,
                'out' => $this->dumpOutFile,
                'err' => $this->dumpErrFile,
            ];
            $this->dumpProc = null;
        }
        $this->dumpOutFile = '';
        $this->dumpErrFile = '';
        $this->dumpOffset = 0;
        $this->dumpErrOffset = 0;
        $this->dumpBuffer = '';
        $this->dumping = false;
    }

    /**
     * Server 每个 tick 调用：回收已退出的 dump 子进程句柄并删除其临时输出文件。
     * 仍在退出的子进程留到下个 tick，避免 proc_close 阻塞事件循环。
     */
    public static function reapFinished(): void
    {
        if (self::$reapQueue === []) {
            return;
        }
        $pending = [];
        foreach (self::$reapQueue as $item) {
            $proc = $item['proc'];
            $st = @proc_get_status($proc);
            if (is_array($st) && ($st['running'] ?? false)) {
                $pending[] = $item;
                continue;
            }
            @proc_close($proc);
            if ((string) $item['out'] !== '') {
                @unlink((string) $item['out']);
            }
            if ((string) $item['err'] !== '') {
                @unlink((string) $item['err']);
            }
        }
        self::$reapQueue = $pending;
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

    /**
     * dump 预检失败：发错误帧后关闭 SSE 流。
     * 此时没有子进程可等，Server 只会在 dump 结束或检测到客户端 EOF 时关连接，
     * 不主动关闭就会永久挂着一个 fd 与一条不结束的 fetch 流。
     */
    private function failSse(string $id, int $code, string $message): void
    {
        $this->sse($id, 'error', ['code' => $code, 'message' => $message]);
        if ($this->client !== null) {
            $this->client->endSse();
        }
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
