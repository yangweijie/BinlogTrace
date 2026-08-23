<?php

declare(strict_types=1);

/**
 * agentRaw/run.php — 原生 PHP WS 代理（绕开 TypePHP 编译器）
 *
 * 功能：监听 TCP 端口，接受浏览器 WebSocket 连接，代理 binlog 事件和查询
 * 用法：php agentRaw/run.php [--port 8080]
 *
 * 与 agent/src/ 的区别：
 * - 使用原生 PHP 事件循环（stream_select + 非阻塞 fread）
 * - 不依赖 TypePHP 编译器
 * - 日志前缀 [agentRaw]
 */

// ═══════════════════════════════════════════════════════════════════════════════
// 1. 加载 agent/src 下的所有类（全局命名空间）
// ═══════════════════════════════════════════════════════════════════════════════

$base = dirname(__DIR__) . '/agent/src/';
$files = [
    'Protocol/Frame.php',       // AgentConfig + AgentConstants
    'WsFrameCodec.php',         // WS 帧编解码 + handshake
    'MySQL/Protocol.php',       // MySQL 协议层
    'MySQL/Client.php',         // MySQL 客户端
    'MetaGatherer.php',         // binlog 元数据采集
];

foreach ($files as $f) {
    $path = $base . $f;
    if (!file_exists($path)) {
        fwrite(STDERR, "[agentRaw] ERROR: $path not found\n");
        exit(1);
    }
    require $path;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 2. 日志辅助
// ═══════════════════════════════════════════════════════════════════════════════

function logMsg(string $msg): void
{
    fwrite(STDERR, "[agentRaw] " . $msg . PHP_EOL);
}

function nowMs(): int
{
    return (int)(microtime(true) * 1000);
}

// ═══════════════════════════════════════════════════════════════════════════════
// 3. WS 帧解析器（buffer-based，非阻塞安全）
//    从 buffer 中尝试解析完整的 WS 帧
//    返回 null 表示数据不足；返回 array 表示解析成功
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 尝试从 buffer 中解析一个完整的 WS 帧
 *
 * @param string $buffer 引用，已解析的帧数据会从 buffer 中移除
 * @return array|null  ['opcode' => int, 'payload' => string, 'consumed' => int] 或 null
 */
function tryParseWsFrame(string &$buffer): ?array
{
    $bufLen = strlen($buffer);

    // 需要至少 2 字节头部
    if ($bufLen < 2) {
        return null;
    }

    $first  = ord($buffer[0]);
    $second = ord($buffer[1]);
    $opcode = $first & 0x0F;
    $masked = ($second & 0x80) !== 0;
    $payloadLen = $second & 0x7F;
    $offset = 2;

    // 扩展 payload 长度
    if ($payloadLen === 126) {
        if ($bufLen < $offset + 2) {
            return null;
        }
        $arr = unpack('n', substr($buffer, $offset, 2));
        $payloadLen = (int)($arr[1] ?? 0);
        $offset += 2;
    } elseif ($payloadLen === 127) {
        if ($bufLen < $offset + 8) {
            return null;
        }
        // 64-bit length (high 16 bits must be 0 per RFC 6455)
        $hi = unpack('N', substr($buffer, $offset, 4));
        $lo = unpack('N', substr($buffer, $offset + 4, 4));
        $payloadLen = (int)($hi[1] ?? 0) * 4294967296 + (int)($lo[1] ?? 0);
        $offset += 8;
    }

    // 检查帧大小限制
    if ($payloadLen > AgentConstants::MAX_FRAME_SIZE) {
        logMsg('Frame too large: ' . $payloadLen . ' bytes');
        return null;
    }

    // 掩码 key
    if ($masked) {
        if ($bufLen < $offset + 4) {
            return null;
        }
        $maskKey = substr($buffer, $offset, 4);
        $offset += 4;
    } else {
        $maskKey = '';
    }

    // payload 数据
    $totalNeed = $offset + $payloadLen;
    if ($bufLen < $totalNeed) {
        return null;
    }

    $payload = substr($buffer, $offset, $payloadLen);
    $consumed = $totalNeed;

    // 解码掩码
    if ($masked && $payloadLen > 0) {
        $payload = wsUnmask($payload, $maskKey);
    }

    // 从 buffer 中移除已解析的数据
    $buffer = substr($buffer, $consumed);

    return [
        'opcode'   => $opcode,
        'payload'  => $payload,
        'consumed' => $consumed,
    ];
}

/**
 * WebSocket 掩码解码（RFC 6455 §5.3）
 */
function wsUnmask(string $payload, string $maskKey): string
{
    $len = strlen($payload);
    $result = '';
    for ($i = 0; $i < $len; $i++) {
        $result .= chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
    }
    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 4. MySQL 包解析器（buffer-based，非阻塞安全）
//    MySQL 包格式：3 字节 payload 长度 (LE) + 1 字节 seq + payload
//    当 payload 长度 = 0xFFFFFF 时，表示分包，需要读取后续包
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 尝试从 buffer 中解析一个完整的 MySQL 包
 *
 * @param string $buffer 引用，已解析的包数据会从 buffer 中移除
 * @return string|null  payload 数据 或 null（数据不足）
 */
function tryParseMysqlPacket(string &$buffer): ?string
{
    $bufLen = strlen($buffer);
    if ($bufLen < 4) {
        return null;
    }

    // 3 字节 payload 长度 (little-endian)
    $payloadLen = ord($buffer[0])
        | (ord($buffer[1]) << 8)
        | (ord($buffer[2]) << 16);

    $need = 4 + $payloadLen;
    if ($bufLen < $need) {
        return null;
    }

    $payload = substr($buffer, 4, $payloadLen);
    $buffer = substr($buffer, $need);

    // 处理分包（payloadLen === 0xFFFFFF = 16777215）
    while ($payloadLen === 16777215 && strlen($buffer) >= 4) {
        $nextLen = ord($buffer[0])
            | (ord($buffer[1]) << 8)
            | (ord($buffer[2]) << 16);
        $nextNeed = 4 + $nextLen;
        if (strlen($buffer) < $nextNeed) {
            return null; // 数据不足
        }
        $payload .= substr($buffer, 4, $nextLen);
        $buffer = substr($buffer, $nextNeed);
        $payloadLen = $nextLen;
    }

    return $payload;
}

// ═══════════════════════════════════════════════════════════════════════════════
// 5. RawConnection — 单连接状态管理
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * RawConnection — 事件循环感知型连接处理器
 *
 * 与 agent/src/ConnectionHandler 的区别：
 * - 不使用 stream_set_timeout + fread 阻塞等待
 * - 不使用 stream_select（由主循环统一管理）
 * - WS 帧和 MySQL 包都使用 buffer-based 解析
 * - 支持非阻塞 fread
 */
class RawConnection
{
    public $wsStream;
    /** @var Client|null */
    public ?Client $mysqlClient = null;
    /** @var resource|null */
    public $mysqlStream = null;

    public bool $dumping = false;
    public bool $connected = false;
    public bool $closing = false;
    public int $serverId;
    public string $binlogFile = '';
    public int $lastHeartbeatTs = 0;
    public int $eventCount = 0;

    // buffer-based 解析
    public string $wsBuffer = '';
    public string $mysqlBuffer = '';

    public function __construct($wsStream)
    {
        $this->wsStream = $wsStream;
        $this->mysqlClient = new Client();
        $this->serverId = random_int(1, 2147483647);
        $this->lastHeartbeatTs = nowMs();
        logMsg('New connection, serverId=' . $this->serverId);
    }

    public function getReadableStreams(): array
    {
        $streams = [$this->wsStream];
        if (is_resource($this->mysqlStream)) {
            $streams[] = $this->mysqlStream;
        }
        return $streams;
    }

    public function isAlive(): bool
    {
        return is_resource($this->wsStream);
    }

    public function close(): void
    {
        try {
            if (is_resource($this->wsStream)) {
                fclose($this->wsStream);
            }
        } catch (\Throwable $e) {
            logMsg('close wsStream error: ' . $e->getMessage());
        }
        if ($this->mysqlClient !== null) {
            $this->mysqlClient->close();
        }
    }

    /**
     * 发送 WS text 帧
     */
    public function sendFrame(string $id, string $type, array $payload): void
    {
        $json = json_encode([
            'v'       => AgentConstants::PROTOCOL_VERSION,
            'id'      => $id,
            'type'    => $type,
            'ts'      => nowMs(),
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        $ok = WsFrameCodec::writeFrame($this->wsStream, 1, $json);
        if ($ok === false) {
            logMsg('writeFrame failed for type=' . $type);
        }
    }

    public function sendError(string $id, int $code, string $message): void
    {
        logMsg('Error ' . $code . ': ' . $message);
        $this->sendFrame($id, 'error', ['code' => $code, 'message' => $message]);
    }

    public function sendHeartbeat(): void
    {
        $this->lastHeartbeatTs = nowMs();
        $this->sendFrame('', 'heartbeat', [
            'ts'        => $this->lastHeartbeatTs,
            'binlogPos' => null,
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// 6. 消息分发 & 处理函数
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * 分发 JSON 消息到对应的处理函数
 */
function dispatchMessage(RawConnection $conn, array $data): void
{
    $type = $data['type'] ?? '';
    $id = $data['id'] ?? '';
    $payload = $data['payload'] ?? [];

    logMsg('Message type=' . $type . ' id=' . $id);

    switch ($type) {
        case 'connect':
            handleConnect($conn, $id, $payload);
            break;
        case 'binlog-dump':
            handleBinlogDump($conn, $id, $payload);
            break;
        case 'query':
            handleQuery($conn, $id, $payload);
            break;
        case 'close':
            handleClose($conn);
            break;
        case 'heartbeat':
            $conn->sendHeartbeat();
            break;
        default:
            $conn->sendError('', AgentConstants::PROTOCOL_ERROR, '未知消息类型: ' . $type);
            break;
    }
}

/**
 * 处理 connect 消息：连接 MySQL + 采集元数据
 */
function handleConnect(RawConnection $conn, string $id, array $payload): void
{
    if ($conn->connected) {
        $conn->sendError($id, AgentConstants::INVALID_PARAM, '已连接，请先 close');
        $conn->closing = true;
        return;
    }

    $host = (string)($payload['host'] ?? '');
    $port = (int)($payload['port'] ?? 3306);
    $user = (string)($payload['user'] ?? '');
    $password = (string)($payload['password'] ?? '');
    $database = (string)($payload['database'] ?? '');
    $timeout = (int)($payload['connectTimeoutMs'] ?? 10000);
    $clientId = (int)($payload['serverId'] ?? 0);

    if ($host === '') {
        $conn->sendError($id, AgentConstants::INVALID_PARAM, 'host 不能为空');
        $conn->closing = true;
        return;
    }

    $conn->serverId = $clientId > 0 ? $clientId : $conn->serverId;

    logMsg('Connecting to MySQL ' . $host . ':' . $port . ' user=' . $user);

    $timeoutSec = max(1, (int)($timeout / 1000));
    $ok = $conn->mysqlClient->connect($host, $port, $user, $password, $database, $timeoutSec);

    if ($ok === false) {
        $conn->sendError($id, AgentConstants::AUTH_FAILED, 'MySQL 认证失败');
        $conn->closing = true;
        return;
    }

    $conn->connected = true;

    // 采集 binlog 元数据（此时 MySQL stream 仍在阻塞模式）
    $meta = new MetaGatherer($conn->mysqlClient, $conn->serverId);
    $binlogMeta = $meta->gatherBinlogMeta();

    if ($binlogMeta['hasBinlog'] === true) {
        $conn->binlogFile = (string)($binlogMeta['binlogFile'] ?? '');
    }

    // 切换 MySQL stream 为非阻塞，加入事件循环
    $mysqlStream = $conn->mysqlClient->getStream();
    if (is_resource($mysqlStream)) {
        if (@stream_set_blocking($mysqlStream, false) === false) {
            logMsg('stream_set_blocking false failed for MySQL stream');
        }
        $conn->mysqlStream = $mysqlStream;
        logMsg('MySQL stream set to non-blocking');
    }

    $conn->sendFrame($id, 'connected', $binlogMeta);
    logMsg('Connected to MySQL, hasBinlog=' . ($binlogMeta['hasBinlog'] ? 'true' : 'false')
        . ' binlogFile=' . ($conn->binlogFile ?: '(none)'));
}

/**
 * 处理 binlog-dump 消息：开始 binlog 事件流
 */
function handleBinlogDump(RawConnection $conn, string $id, array $payload): void
{
    if (!$conn->connected) {
        $conn->sendError($id, AgentConstants::PROXY_NOT_READY, '尚未连接 MySQL');
        return;
    }

    $fileName = (string)($payload['binlogFile'] ?? $conn->binlogFile);
    $filePos = (int)($payload['binlogPos'] ?? 4);
    $flags = (int)($payload['slaveFlags'] ?? 0);

    if ($fileName === '') {
        $conn->sendError($id, AgentConstants::INVALID_PARAM, 'binlogFile 不能为空');
        return;
    }

    logMsg('binlog-dump: file=' . $fileName . ' pos=' . $filePos . ' flags=' . $flags);

    $ok = $conn->mysqlClient->binlogDump($fileName, $filePos, $conn->serverId, $flags);
    if ($ok === false) {
        $conn->sendError($id, AgentConstants::BINLOG_POSITION_INVALID, 'binlog-dump 命令发送失败');
        return;
    }

    $conn->dumping = true;
    $conn->lastHeartbeatTs = nowMs();
    $conn->sendFrame($id, 'dump-started', [
        'binlogFile' => $fileName,
        'binlogPos'  => $filePos,
    ]);
    logMsg('binlog-dump started, dumping=true');
}

/**
 * 处理 query 消息：执行只读查询
 */
function handleQuery(RawConnection $conn, string $id, array $payload): void
{
    $sql = (string)($payload['sql'] ?? '');
    $trimmed = trim($sql);

    if (!preg_match('/^\s*(SELECT|SHOW)\b.*$/i', $trimmed)) {
        $conn->sendError($id, AgentConstants::PROTOCOL_ERROR, '仅允许只读查询');
        return;
    }

    logMsg('Query: ' . substr($sql, 0, 100));

    // 临时切换为阻塞模式执行查询
    $mysqlStream = $conn->mysqlClient->getStream();
    if (is_resource($mysqlStream)) {
        @stream_set_blocking($mysqlStream, true);
        @stream_set_timeout($mysqlStream, 10);
    }

    $result = $conn->mysqlClient->query($sql);

    // 恢复非阻塞模式
    if (is_resource($mysqlStream)) {
        @stream_set_blocking($mysqlStream, false);
    }

    if ($result === false) {
        $conn->sendError($id, AgentConstants::PARSE_ERROR, '查询失败');
        return;
    }

    $colOut = [];
    foreach (($result['columns'] ?? []) as $col) {
        $colOut[] = [
            'name' => (string)($col['name'] ?? ''),
            'type' => (string)($col['type'] ?? ''),
        ];
    }

    $conn->sendFrame($id, 'query-result', [
        'columns' => $colOut,
        'rows'    => $result['rows'] ?? [],
    ]);
    logMsg('Query result: ' . count($colOut) . ' columns, ' . count($result['rows'] ?? []) . ' rows');
}

/**
 * 处理 close 消息：优雅关闭连接
 */
function handleClose(RawConnection $conn): void
{
    logMsg('Close requested');
    $conn->dumping = false;

    try {
        WsFrameCodec::writeClose($conn->wsStream);
    } catch (\Throwable $e) {
        logMsg('writeClose error: ' . $e->getMessage());
    }

    $conn->mysqlClient?->close();
    $conn->closing = true;
}

/**
 * 处理 WS close 帧
 */
function handleWsClose(RawConnection $conn): void
{
    logMsg('WS close frame received');
    $conn->dumping = false;

    try {
        WsFrameCodec::writeClose($conn->wsStream);
    } catch (\Throwable $e) {
        logMsg('writeClose reply error: ' . $e->getMessage());
    }

    $conn->mysqlClient?->close();
    $conn->closing = true;
}

/**
 * 转发 binlog 事件给浏览器
 */
function relayBinlogEvent(RawConnection $conn, string $raw): void
{
    if (strlen($raw) < 19) {
        logMsg('Event too short: ' . strlen($raw) . ' bytes');
        return;
    }

    $h = unpack('Vtimestamp/Ctype/VserverId/VeventSize/VlogPos/vflags', substr($raw, 0, 19));
    $eventType = (int)($h['type'] ?? 0);

    // 检查事务压缩标志 (MySQL 8.0.20+)
    $flags = unpack('v', substr($raw, 17, 2));
    if ((int)($flags[1] ?? 0) & 0x02) {
        $conn->sendError('', AgentConstants::TRANSACTION_COMPRESSED, 'MySQL 8.0.20+ 事务压缩事件无法解码');
        $conn->dumping = false;
        return;
    }

    // ROTATE 事件（eventType === 4）
    if ($eventType === 4 && strlen($raw) >= 23) {
        $oldFile = $conn->binlogFile;
        $conn->binlogFile = rtrim(substr($raw, 23), chr(0));
        logMsg('ROTATE: ' . $oldFile . ' → ' . $conn->binlogFile);
    }

    $conn->sendFrame('', 'binlog-event', [
        'raw'        => base64_encode($raw),
        'eventType'  => $eventType,
        'binlogFile' => $conn->binlogFile,
        'binlogPos'  => (int)($h['logPos'] ?? 0),
        'timestamp'  => (int)($h['timestamp'] ?? 0),
        'serverId'   => (int)($h['serverId'] ?? 0),
    ]);

    $conn->eventCount++;
    if ($conn->eventCount % 100 === 0) {
        logMsg('Relayed ' . $conn->eventCount . ' events');
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// 7. 主事件循环
// ═══════════════════════════════════════════════════════════════════════════════

// 解析端口
$port = 8080;
if (isset($argv[1]) && $argv[1] === '--port' && isset($argv[2])) {
    $port = (int)$argv[2];
}

// 创建 TCP 服务器
$server = @stream_socket_server(
    'tcp://0.0.0.0:' . $port,
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
);

if ($server === false) {
    logMsg('启动失败: ' . $errstr);
    exit(1);
}

stream_set_blocking($server, false);
logMsg('监听 0.0.0.0:' . $port);

// 连接列表
/** @var RawConnection[] $connections */
$connections = [];

// 清理关闭连接
function cleanupConnections(array &$connections): void
{
    $toClose = [];
    foreach ($connections as $idx => $conn) {
        if ($conn->closing) {
            logMsg('Cleaning up connection serverId=' . $conn->serverId);
            $conn->close();
            $toClose[] = $idx;
        } elseif (!$conn->isAlive()) {
            logMsg('Dead connection serverId=' . $conn->serverId);
            $conn->close();
            $toClose[] = $idx;
        }
    }
    // 从后往前删除，避免索引偏移
    for ($i = count($toClose) - 1; $i >= 0; $i--) {
        unset($connections[$toClose[$i]]);
    }
    $connections = array_values($connections);
}

while (true) {
    // 构建读取集合：server socket + 所有连接的 WS/MySQL stream
    $read = [$server];
    foreach ($connections as $conn) {
        $read = array_merge($read, $conn->getReadableStreams());
    }

    $write = null;
    $except = null;
    $changed = @stream_select($read, $write, $except, AgentConstants::HEARTBEAT_INTERVAL_MS / 1000);

    if ($changed === false) {
        // stream_select 错误（可能被信号中断），跳过本次迭代
        continue;
    }

    if ($changed === 0) {
        // 超时 → 发送心跳给所有 dumping 中的连接
        foreach ($connections as $conn) {
            if ($conn->dumping && !$conn->closing) {
                $conn->sendHeartbeat();
            }
        }
        continue;
    }

    // ── 接受新连接 ──────────────────────────────────────────────
    if (in_array($server, $read, true)) {
        $client = @stream_socket_accept($server, 0);
        if ($client === false) {
            continue; // 无待处理连接（stream_select 报告了但已处理完）
        }

        $conn = new RawConnection($client);

        // 在阻塞模式下执行 WS 握手
        $handshakeOk = WsFrameCodec::doHandshake($client);
        if ($handshakeOk === false) {
            logMsg('Handshake failed, closing connection');
            try {
                fclose($client);
            } catch (\Throwable $e) {
                logMsg('fclose after failed handshake: ' . $e->getMessage());
            }
            continue;
        }

        logMsg('Handshake OK');

        // 握手成功 → 切换为非阻塞
        @stream_set_blocking($client, false);
        $connections[] = $conn;
    }

    // ── 处理每个连接 ────────────────────────────────────────────
    foreach ($connections as $idx => $conn) {
        if ($conn->closing) {
            continue;
        }
        if (!$conn->isAlive()) {
            $conn->close();
            unset($connections[$idx]);
            continue;
        }

        // 检查 except 集合（错误）
        if (is_array($except) && in_array($conn->wsStream, $except, true)) {
            logMsg('WS stream exception');
            $conn->closing = true;
            continue;
        }

        // ── MySQL stream 有数据 ─────────────────────────────────
        if (is_resource($conn->mysqlStream)) {
            if (is_array($except) && in_array($conn->mysqlStream, $except, true)) {
                logMsg('MySQL stream exception');
                $conn->sendError('', AgentConstants::NETWORK_UNREACHABLE, 'MySQL 连接异常');
                $conn->closing = true;
                continue;
            }

            if (in_array($conn->mysqlStream, $read, true)) {
                $chunk = @fread($conn->mysqlStream, 65536);
                if ($chunk === false || $chunk === '') {
                    $meta = @stream_get_meta_data($conn->mysqlStream);
                    $eof = $meta['eof'] ?? false;
                    logMsg('MySQL stream ' . ($eof ? 'closed (EOF)' : 'read failed'));
                    $conn->sendError('', AgentConstants::NETWORK_UNREACHABLE, 'MySQL 连接断开');
                    $conn->closing = true;
                    continue;
                }

                $conn->mysqlBuffer .= $chunk;

                // 解析 MySQL 包（binlog 事件）
                while (true) {
                    $pkt = tryParseMysqlPacket($conn->mysqlBuffer);
                    if ($pkt === null) {
                        break; // 数据不足，等待更多
                    }
                    if ($pkt === '') {
                        continue; // 空包
                    }
                    relayBinlogEvent($conn, $pkt);
                }
            }
        }

        // ── WS stream 有数据 ────────────────────────────────────
        if (in_array($conn->wsStream, $read, true)) {
            $chunk = @fread($conn->wsStream, 65536);
            if ($chunk === false || $chunk === '') {
                $meta = @stream_get_meta_data($conn->wsStream);
                $eof = $meta['eof'] ?? false;
                logMsg('WS stream ' . ($eof ? 'closed (EOF)' : 'read failed'));
                $conn->closing = true;
                continue;
            }

            $conn->wsBuffer .= $chunk;

            // 解析 WS 帧
            while (true) {
                $frame = tryParseWsFrame($conn->wsBuffer);
                if ($frame === null) {
                    break; // 数据不足
                }

                $opcode = (int)$frame['opcode'];
                $payload = (string)$frame['payload'];

                logMsg('WS frame opcode=0x' . dechex($opcode)
                    . ' payloadLen=' . strlen($payload));

                // 关闭帧
                if ($opcode === 8) {
                    handleWsClose($conn);
                    break 2;
                }

                // Ping / Pong
                if ($opcode === 9) {
                    // Ping → 回复 Pong
                    WsFrameCodec::writePong($conn->wsStream, $payload);
                    continue;
                }
                if ($opcode === 10) {
                    // Pong（浏览器回复），忽略
                    continue;
                }

                // Text 帧
                if ($opcode === 1) {
                    try {
                        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
                        if (!is_array($data) || !isset($data['type'])) {
                            $conn->sendError('', AgentConstants::PROTOCOL_ERROR, '非 JSON 帧或缺少 type');
                            continue;
                        }
                        dispatchMessage($conn, $data);
                    } catch (\Throwable $e) {
                        logMsg('JSON parse error: ' . $e->getMessage());
                        $conn->sendError('', AgentConstants::PARSE_ERROR, 'JSON 解析失败');
                    }
                }
                // 其他 opcode 忽略
            }
        }
    }

    // ── 清理关闭连接 ────────────────────────────────────────────
    cleanupConnections($connections);
}
