<?php

declare(strict_types=1);

namespace DmsAgent\Mysql;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/**
 * AsyncClient — 基于 Workerman AsyncTcpConnection 的异步 MySQL 客户端
 *
 * 与 agent/src/MySQL/Client.php（TypePHP 同步版）功能对齐，但改为：
 *   - 非阻塞异步连接（AsyncTcpConnection + 事件循环）
 *   - 接收缓冲区 + 状态机解析 MySQL 包（无阻塞 fread）
 *   - 回调风格 API：connect / query / binlogDump
 *
 * 状态机：
 *   auth（握手 → 认证） → idle（就绪） → result（COM_QUERY 结果集）
 *                                    → binlog（COM_BINLOG_DUMP 事件流）
 *
 * 已修正 TypePHP 版的两处协议缺陷：
 *   1. auth-plugin-data-part-2 起始偏移（verLen+32 而非 verLen+28）
 *   2. CLIENT_CONNECT_WITH_DB 时 database 应为 NUL 结尾字符串（而非 lenenc 编码）
 */
final class AsyncClient
{
    /**
     * 客户端能力位（规范子集，不含 SSL）：
     *   LONG_PASSWORD|FOUND_ROWS|LONG_FLAG|CONNECT_WITH_DB|PROTOCOL_41|TRANSACTIONS
     *   |SECURE_CONNECTION|MULTI_STATEMENTS|MULTI_RESULTS|PS_MULTI_RESULTS|PLUGIN_AUTH
     * 注意：必须含 SECURE_CONNECTION(0x8000)——否则服务端按 NUL 结尾解析认证响应导致整包错位；
     * 不含 PLUGIN_AUTH_LENENC_CLIENT_DATA——认证响应用 1 字节长度（SECURE_CONNECTION 语义）。
     */
    private const CAP_CLIENT = 0x000FA20F;
    private const PKT_OK = 0x00;
    private const PKT_EOF = 0xFE;
    private const PKT_ERR = 0xFF;
    private const PKT_LOCAL_INFILE = 0xFB;
    private const MAX_PACKET = 0xFFFFFF;

    /** 状态 */
    private const ST_AUTH = 'auth';
    private const ST_IDLE = 'idle';
    private const ST_RESULT = 'result';
    private const ST_BINLOG = 'binlog';

    private ?AsyncTcpConnection $conn = null;

    /** 接收缓冲区（累积直到能解析出完整 MySQL 包） */
    private string $buffer = '';

    /** 跨 16MB 分片包的累积载荷 */
    private string $packetAccum = '';

    /** 当前状态 */
    private string $state = self::ST_IDLE;

    /** 握手是否完成（auth 状态下区分首个握手包 vs 认证响应包） */
    private bool $handshakeDone = false;

    /** result 阶段：期望的列数 / 已收集列 / 已收集行 / 列后 EOF 是否已消费 */
    private int $fieldCount = 0;
    private array $columns = [];
    private array $rows = [];
    private bool $columnsEofReceived = false;

    private string $authUser = '';
    private string $authPassword = '';
    private string $authDatabase = '';
    private string $serverScramble = '';
    private int $characterSet = 33;
    private int $capabilities = 0;
    private int $connectionId = 0;
    private string $serverVersion = '';
    private string $binlogFile = '';

    /** 认证阶段客户端侧序号（auth=1 → AuthSwitch 响应=3 → RSA 密钥请求=5 → 加密口令=7） */
    private int $authPacketSeq = 0;
    /** AuthSwitchRequest 携带的新 scramble（caching_sha2 完整认证用） */
    private string $authSwitchScramble = '';
    /** 是否在等待 RSA 公钥包（caching_sha2 完整认证） */
    private bool $authWaitKey = false;

    /** @var int|false 连接超时定时器 id */
    private $connectTimer = false;

    /** @var callable|null (int $code, string $message) */
    private $onError = null;
    /** @var callable|null (array $meta) */
    private $onConnected = null;
    /** @var callable|null (array $result) */
    private $onQueryResult = null;
    /** @var callable|null (array $event) */
    private $onBinlogEvent = null;
    /** SET @master_binlog_checksum 声明成功 → 事件尾部带 4 字节 CRC32（ROTATE 文件名解析等需按此剥离） */
    public bool $binlogChecksummed = false;

    // ─── 连接与认证 ────────────────────────────────────────

    /**
     * 异步连接 MySQL 并完成认证。
     *
     * @param callable $onConnected 认证成功后回调
     * @param callable $onError     (int $code, string $message) 连接失败 / 认证失败
     */
    public function connect(
        string $host,
        int $port,
        string $user,
        string $password,
        string $database,
        int $timeoutSec,
        callable $onConnected,
        callable $onError
    ): void {
        $this->authUser = $user;
        $this->authPassword = $password;
        $this->authDatabase = $database;
        $this->onConnected = $onConnected;
        $this->onError = $onError;
        $this->state = self::ST_AUTH;
        $this->handshakeDone = false;
        $this->serverScramble = '';
        $this->authPacketSeq = 0;
        $this->authSwitchScramble = '';
        $this->authWaitKey = false;

        $conn = new AsyncTcpConnection("tcp://{$host}:{$port}");
        $this->conn = $conn;

        $self = $this;
        $conn->onMessage = function (AsyncTcpConnection $c, string $data) use ($self): void {
            $self->onData($data);
        };
        $conn->onError = function (AsyncTcpConnection $c, int $code, string $msg) use ($self): void {
            $self->cancelConnectTimer();
            $self->notifyError(1002, 'MySQL 连接失败: ' . $msg);
        };
        $conn->onClose = function () use ($self): void {
            $self->cancelConnectTimer();
            $self->onConnectionClosed();
        };

        if ($timeoutSec > 0) {
            $this->connectTimer = Timer::add(
                max(1, $timeoutSec),
                function () use ($self): void {
                    $self->cancelConnectTimer();
                    if ($self->state === self::ST_AUTH && $self->conn !== null) {
                        $conn = $self->conn;
                        // notifyError 可能触发上层 teardownMysql → close() 把 conn 置 null，先捕获引用
                        $self->notifyError(1007, 'MySQL 连接超时');
                        $conn->close();
                    }
                },
                null,
                false
            );
        }
        $conn->connect();
    }

    /** 发送只读查询（结果以数组形式回调） */
    public function query(string $sql, callable $onResult, callable $onError): void
    {
        if ($this->conn === null || $this->state !== self::ST_IDLE) {
            $onError(1006, 'MySQL 未就绪（busy 或未连接）');
            return;
        }
        $this->onQueryResult = $onResult;
        $this->onError = $onError;
        $this->state = self::ST_RESULT;
        $this->resetResultState();
        $this->sendPacket("\x03" . $sql, 0);
    }

    /** 发起 binlog dump，事件经 onEvent 持续回调 */
    public function binlogDump(
        string $file,
        int $pos,
        int $serverId,
        int $flags,
        callable $onEvent,
        callable $onError
    ): void {
        if ($this->conn === null || $this->state !== self::ST_IDLE) {
            $onError(1006, 'MySQL 未就绪');
            return;
        }
        $this->onBinlogEvent = $onEvent;
        $this->onError = $onError;
        $this->state = self::ST_BINLOG;
        $this->binlogFile = $file;
        $packet = "\x12" . pack('V', $pos) . pack('v', $flags) . pack('V', $serverId) . $file;
        $this->sendPacket($packet, 0);
    }

    /** 关闭连接（静默，不再触发任何错误回调） */
    public function close(): void
    {
        $this->cancelConnectTimer();
        $this->onConnected = null;
        $this->onError = null;
        $this->onQueryResult = null;
        $this->onBinlogEvent = null;
        $this->state = self::ST_IDLE;
        if ($this->conn !== null) {
            $c = $this->conn;
            $this->conn = null;
            $c->close();
        }
    }

    // ─── 数据接收 ─────────────────────────────────────────

    private function onData(string $data): void
    {
        $this->buffer .= $data;
        $this->drain();
    }

    private function drain(): void
    {
        while (true) {
            // 认证阶段：服务端首包必须是 v10 握手。MySQL 包有 4 字节头（3 字节长度 + 1 字节序号），
            // 所以先看"声明长度"：握手包 payload 通常 < 1KB，超限说明目标不是 MySQL（或字节错位）。
            // 其余非法首包由 parseHandshake 的 0x0a 校验兜底（见 handlePacket）。
            if ($this->state === self::ST_AUTH && !$this->handshakeDone && strlen($this->buffer) >= 4) {
                $payloadLen = ord($this->buffer[0])
                    | (ord($this->buffer[1]) << 8)
                    | (ord($this->buffer[2]) << 16);
                if ($payloadLen > 4096) {
                    $this->buffer = '';
                    $this->state = self::ST_IDLE;
                    $this->notifyError(1010, 'MySQL 握手包异常（声明长度 ' . $payloadLen . '，目标可能不是 MySQL 服务）');
                    return;
                }
            }
            $packet = $this->tryReadPacket();
            if ($packet === null) {
                break;
            }
            $this->handlePacket($packet);
        }
    }

    /**
     * 从缓冲区解析一个完整 MySQL 包（自动拼接 16MB 分片链）。
     *
     * @return string|null 完整 payload；数据不足时返回 null
     */
    private function tryReadPacket(): ?string
    {
        while (true) {
            $bufLen = strlen($this->buffer);
            if ($bufLen < 4) {
                return null;
            }
            $payloadLen = ord($this->buffer[0])
                | (ord($this->buffer[1]) << 8)
                | (ord($this->buffer[2]) << 16);
            $total = 4 + $payloadLen;
            if ($bufLen < $total) {
                return null;
            }
            $this->packetAccum .= substr($this->buffer, 4, $payloadLen);
            $this->buffer = substr($this->buffer, $total);
            if ($payloadLen < self::MAX_PACKET) {
                $packet = $this->packetAccum;
                $this->packetAccum = '';
                return $packet;
            }
            // payloadLen == 0xFFFFFF：分片包，继续读取下一段
        }
    }

    private function handlePacket(string $payload): void
    {
        switch ($this->state) {
            case self::ST_AUTH:
                if (!$this->handshakeDone) {
                    $this->handshakeDone = true;
                    if ($this->parseHandshake($payload)) {
                        $this->sendAuthPacket();
                    } else {
                        $this->state = self::ST_IDLE;
                        $this->notifyError(1010, 'MySQL 握手包解析失败');
                    }
                } else {
                    $this->handleAuthResponse($payload);
                }
                break;
            case self::ST_RESULT:
                $this->handleResultPacket($payload);
                break;
            case self::ST_BINLOG:
                $this->handleBinlogPacket($payload);
                break;
            default:
                // idle 状态下不应收到服务端主动数据
                break;
        }
    }

    private function onConnectionClosed(): void
    {
        $this->conn = null;
        if ($this->state === self::ST_AUTH && !$this->handshakeDone) {
            // 服务端在握手完成前关闭连接（如非 MySQL 服务、或认证中途断开）
            $this->state = self::ST_IDLE;
            $this->notifyError(1002, 'MySQL 连接在握手完成前被关闭');
        } elseif ($this->state === self::ST_RESULT) {
            $this->notifyError(1002, 'MySQL 连接在查询中被关闭');
        } elseif ($this->state === self::ST_BINLOG) {
            $this->notifyError(1002, 'MySQL 连接已断开，binlog 追踪中断');
        }
        // auth 阶段且握手已完成后的关闭已由认证流程上报，避免重复
    }

    // ─── 握手与认证 ───────────────────────────────────────

    /**
     * 解析握手包（protocol v10）。
     * 注意：auth-plugin-data-part-2 起始偏移为 verLen + 32（10 字节 reserved 之后）。
     */
    private function parseHandshake(string $pkt): bool
    {
        if ($pkt === '' || ord($pkt[0]) !== 10) {
            return false;
        }
        $verLen = strpos($pkt, "\0", 1);
        if ($verLen === false || $verLen < 5 || strlen($pkt) < $verLen + 32) {
            return false;
        }
        $this->serverVersion = substr($pkt, 1, $verLen - 1);

        $connId = unpack('V', substr($pkt, $verLen + 1, 4));
        $this->connectionId = (int) ($connId[1] ?? 0);

        $authData1 = substr($pkt, $verLen + 5, 8);
        $lowCap = unpack('v', substr($pkt, $verLen + 14, 2));
        $this->characterSet = ord($pkt[$verLen + 16]);
        $highCap = unpack('v', substr($pkt, $verLen + 19, 2));
        $this->capabilities = ((int) ($highCap[1] ?? 0) << 16) | (int) ($lowCap[1] ?? 0);

        $authDataLen = ord($pkt[$verLen + 21]);
        if ($authDataLen > 8) {
            $authData2 = substr($pkt, $verLen + 32, $authDataLen - 8);
            $this->serverScramble = $authData1 . $authData2;
        } else {
            $this->serverScramble = $authData1;
        }
        return true;
    }

    private function sendAuthPacket(): void
    {
        $auth = $this->computeNativeAuth($this->authPassword);
        $packet = pack('V', self::CAP_CLIENT)
            . pack('V', 16777215)       // max packet size
            . chr($this->characterSet)
            . str_repeat("\0", 23)      // reserved
            . $this->authUser . "\0"
            . chr(strlen($auth)) . $auth        // 1 字节长度（CLIENT_SECURE_CONNECTION）
            // CLIENT_CONNECT_WITH_DB 已置位：库名字段必须始终存在（空库也发一个 NUL），
            // 否则服务端会把后续插件名误读为库名（报 Unknown database 'mysql_native_password'）
            . $this->authDatabase . "\0"
            . "mysql_native_password\0";
        // 认证包 sequence = 1（服务端握手包 seq=0 之后 +1）
        $this->authPacketSeq = 1;
        $this->sendPacket($packet, 1);
    }

    private function computeNativeAuth(string $password): string
    {
        $sha1Pwd = hash('sha1', $password, true);
        $sha1Scramble = hash('sha1', $sha1Pwd . $this->serverScramble, true);
        $result = '';
        for ($i = 0; $i < 20; $i++) {
            $result .= chr(ord($sha1Scramble[$i]) ^ ord($sha1Pwd[$i]));
        }
        return $result;
    }

    private function handleAuthResponse(string $payload): void
    {
        if ($payload === '') {
            $this->notifyError(1010, 'MySQL 认证响应为空');
            return;
        }
        $first = ord($payload[0]);

        // caching_sha2 完整认证的 RSA 公钥包：AuthMoreData [0x01] + PEM 公钥（直接跟在 0x01 后）
        if ($first === 0x01 && $this->authWaitKey) {
            $this->authWaitKey = false;
            $this->sendFullAuthPassword(substr($payload, 1));
            return;
        }

        if ($first === self::PKT_OK) {
            $this->cancelConnectTimer();
            $this->state = self::ST_IDLE;
            $this->authWaitKey = false;
            $cb = $this->onConnected;
            $this->onConnected = null;
            if ($cb !== null) {
                $cb();
            }
            return;
        }
        if ($first === self::PKT_ERR) {
            $this->state = self::ST_IDLE;
            $this->authWaitKey = false;
            $this->notifyError(1001, 'MySQL 认证失败: ' . $this->parseErrorMessage($payload));
            return;
        }
        if ($first === 0xFE) {
            // AuthSwitchRequest（如 caching_sha2_password）
            $this->handleAuthSwitch($payload);
            return;
        }
        if ($first === 0x01) {
            $code = ord($payload[1] ?? 0);
            if ($code === 0x03) {
                // caching_sha2 快速认证成功标记，其后服务端还会发 OK/ERR
                return;
            }
            if ($code === 0x04) {
                // caching_sha2 完整认证：向服务端请求 RSA 公钥
                $this->authWaitKey = true;
                $this->sendPacket("\x02", $this->nextAuthSeq());
                return;
            }
        }
        $this->state = self::ST_IDLE;
        $this->notifyError(1010, '意外认证响应: 0x' . dechex($first));
    }

    /** 认证阶段客户端发送序号：auth=1 之后每次 +2（握手=0、AuthSwitch=2、0x04=4、密钥=6 …） */
    private function nextAuthSeq(): int
    {
        $this->authPacketSeq += 2;
        return $this->authPacketSeq;
    }

    /** 认证插件切换（0xFE）：解析插件名 + 新 scramble，按插件计算响应 */
    private function handleAuthSwitch(string $payload): void
    {
        $pos = 1;
        $len = strlen($payload);
        $plugin = '';
        while ($pos < $len && $payload[$pos] !== "\0") {
            $plugin .= $payload[$pos];
            $pos++;
        }
        $pos++; // 跳过 NUL
        $scramble = substr($payload, $pos);
        $this->authSwitchScramble = $scramble;

        if ($plugin === 'caching_sha2_password') {
            $response = $this->computeCachingSha2($this->authPassword, $scramble);
            // sequence：握手=0(服务端) → 认证=1(客户端) → AuthSwitch=2(服务端) → 响应=3(客户端)
            $this->sendPacket($response, $this->nextAuthSeq());
            return;
        }
        $this->state = self::ST_IDLE;
        $this->notifyError(1001, '不支持的认证插件: ' . $plugin);
    }

    /** caching_sha2 完整认证：口令与 scramble 逐字节异或（含 NUL 终止符）→ RSA-OAEP 加密 */
    private function sendFullAuthPassword(string $pem): void
    {
        $password = $this->authPassword;
        $scramble = $this->authSwitchScramble;
        $scrambleLen = strlen($scramble) > 0 ? strlen($scramble) : 1;
        // 明文 = (口令 ^ scramble) 逐字节 + (NUL ^ scramble[口令长度])：
        // 服务端解密后对整个 blob 再 XOR scramble 才能还原口令 + NUL（mysqlnd 实测确认）
        $cleartext = '';
        $pwLen = strlen($password);
        for ($i = 0; $i <= $pwLen; $i++) {
            $byte = $i < $pwLen ? $password[$i] : "\0";
            $cleartext .= $byte ^ $scramble[$i % $scrambleLen];
        }

        // 服务端发来的公钥可能带 BEGIN/END 行，统一规整后包上头尾
        $clean = preg_replace('/-----BEGIN[^-]+-----|-----END[^-]+-----|\s+/', '', $pem);
        $pubKey = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split($clean, 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        $encrypted = '';
        $ok = @openssl_public_encrypt($cleartext, $encrypted, $pubKey, OPENSSL_PKCS1_OAEP_PADDING);
        if (!$ok || $encrypted === '') {
            $this->state = self::ST_IDLE;
            $this->notifyError(1001, 'caching_sha2 RSA 加密失败');
            return;
        }
        $this->sendPacket($encrypted, $this->nextAuthSeq());
    }

    /** caching_sha2_password 快速认证：SHA256 三重散列 + XOR scramble */
    private function computeCachingSha2(string $password, string $scramble): string
    {
        $hash1 = hash('sha256', $password, true);
        $hash2 = hash('sha256', $hash1, true);
        $hash3 = hash('sha256', $hash2 . $scramble, true);
        $result = '';
        for ($i = 0; $i < 32; $i++) {
            $result .= chr(ord($hash3[$i]) ^ ord($hash1[$i]));
        }
        return $result;
    }

    // ─── 查询结果集 ───────────────────────────────────────

    private function handleResultPacket(string $payload): void
    {
        if ($payload === '') {
            return;
        }
        $first = ord($payload[0]);

        // 阶段一：列数 / OK / ERR / LOCAL INFILE
        if ($this->fieldCount === 0) {
            if ($first === self::PKT_OK) {
                $this->finishQuery();
                return;
            }
            if ($first === self::PKT_ERR) {
                $this->abortQuery('MySQL 查询失败: ' . $this->parseErrorMessage($payload));
                return;
            }
            if ($first === self::PKT_LOCAL_INFILE) {
                $this->abortQuery('不支持 LOCAL INFILE 查询');
                return;
            }
            $consumed = 0;
            $count = $this->decodeLengthEncodedIntAt($payload, 0, $consumed);
            $this->fieldCount = $count ?? 0;
            if ($this->fieldCount <= 0) {
                $this->finishQuery();
            }
            return;
        }

        // 阶段二：列定义
        if (count($this->columns) < $this->fieldCount) {
            $col = $this->parseColumnDef($payload);
            if ($col !== null) {
                $this->columns[] = $col;
            }
            return;
        }

        // 阶段三：列后 EOF（部分服务端省略该包，则把本包当首行处理）
        if (!$this->columnsEofReceived) {
            $this->columnsEofReceived = true;
            if ($first !== self::PKT_EOF) {
                $row = $this->parseRow($payload, $this->fieldCount);
                if ($row !== null) {
                    $this->rows[] = $row;
                }
            }
            return;
        }

        // 阶段四：行数据直到 EOF/OK
        if ($first === self::PKT_EOF || $first === self::PKT_OK) {
            $this->finishQuery();
            return;
        }
        $row = $this->parseRow($payload, $this->fieldCount);
        if ($row !== null) {
            $this->rows[] = $row;
        }
    }

    private function finishQuery(): void
    {
        $result = ['columns' => $this->columns, 'rows' => $this->rows];
        $this->resetResultState();
        $this->state = self::ST_IDLE;
        $cb = $this->onQueryResult;
        $this->onQueryResult = null;
        if ($cb !== null) {
            $cb($result);
        }
    }

    private function abortQuery(string $message): void
    {
        $this->resetResultState();
        $this->state = self::ST_IDLE;
        $cb = $this->onError;
        $this->onError = null;
        if ($cb !== null) {
            $cb(1005, $message);
        }
    }

    private function resetResultState(): void
    {
        $this->fieldCount = 0;
        $this->columns = [];
        $this->rows = [];
        $this->columnsEofReceived = false;
    }

    // ─── binlog 事件流 ────────────────────────────────────

    private function handleBinlogPacket(string $payload): void
    {
        if ($payload === '') {
            return;
        }
        // 服务器拒绝 dump（如文件不存在 / 位置非法）
        if (ord($payload[0]) === self::PKT_ERR) {
            $this->state = self::ST_IDLE;
            $cb = $this->onError;
            $this->onError = null;
            if ($cb !== null) {
                $cb(1011, 'binlog-dump 失败: ' . $this->parseErrorMessage($payload));
            }
            return;
        }
        // MySQL binlog dump 流每个事件包以 0x00 引导字节开头（服务端附带的 OK 标记，
        // 官方 mysqlbinlog 读远端流同样跳过；实测 8.0.36 恒有）。仅当偏移 1 能解出
        // 合法事件头（类型 1..42 且 size==实收-1）才剥离，避免误伤真实时间戳低字节
        // 恰好为 0x00 的事件。
        if (ord($payload[0]) === 0x00 && strlen($payload) >= 20) {
            $probe = @unpack('Vtimestamp/Ctype/VserverId/VeventSize/VlogPos/vflags', substr($payload, 1, 19));
            if (is_array($probe)
                && (int) $probe['type'] > 0 && (int) $probe['type'] < 43
                && (int) $probe['eventSize'] === strlen($payload) - 1) {
                $payload = substr($payload, 1);
            }
        }
        if (strlen($payload) < 19) {
            return;
        }
        $h = unpack('Vtimestamp/Ctype/VserverId/VeventSize/VlogPos/vflags', substr($payload, 0, 19));
        $eventType = (int) $h['type'];
        $flags = (int) $h['flags'];

        // 事务压缩事件（MySQL 8.0.20+ binlog_transaction_compression=ON）无法解码
        if (($flags & 0x02) !== 0) {
            $this->state = self::ST_IDLE;
            $cb = $this->onError;
            $this->onError = null;
            if ($cb !== null) {
                $cb(1012, 'MySQL 8.0.20+ 事务压缩事件无法解码');
            }
            return;
        }

        // 声明校验和后，服务端给每个事件追加 4 字节 CRC32 尾部。parser/WASM 按无校验和
        // 格式解码（且收不到 FDE 无从自判），透传前剥离并同步修正头部 eventSize 字段，
        // 保持事件自洽。守卫 size==实收 防误伤未声明校验和的流。
        if ($this->binlogChecksummed && strlen($payload) >= 23 && (int) $h['eventSize'] === strlen($payload)) {
            $payload = substr($payload, 0, -4);
            $payload = substr_replace($payload, pack('V', (int) $h['eventSize'] - 4), 9, 4);
            $h['eventSize'] = (int) $h['eventSize'] - 4;
        }

        // ROTATE_EVENT：新 binlog 文件名（头部 19 + pos 8 = 偏移 27 起，NUL 结尾；
        // CRC 已在上方剥离，故不再单独处理尾部）
        if ($eventType === 4 && strlen($payload) >= 27) {
            $this->binlogFile = rtrim(substr($payload, 27), "\0");
        }

        $cb = $this->onBinlogEvent;
        if ($cb !== null) {
            $cb([
                'raw' => base64_encode($payload),
                'eventType' => $eventType,
                'binlogFile' => $this->binlogFile,
                'binlogPos' => (int) $h['logPos'],
                'timestamp' => (int) $h['timestamp'],
                'serverId' => (int) $h['serverId'],
            ]);
        }
    }

    // ─── 底层收发 ─────────────────────────────────────────

    private function sendPacket(string $payload, int $seq): void
    {
        if ($this->conn === null) {
            return;
        }
        // MySQL 包头 = 3 字节小端长度 + 1 字节序号（不能用 pack('V')，那是 4 字节）
        $len = strlen($payload);
        $header = chr($len & 0xFF) . chr(($len >> 8) & 0xFF) . chr(($len >> 16) & 0xFF) . chr($seq & 0xFF);
        $this->conn->send($header . $payload);
    }

    private function notifyError(int $code, string $message): void
    {
        $cb = $this->onError;
        if ($cb !== null) {
            $cb($code, $message);
        }
    }

    private function cancelConnectTimer(): void
    {
        if ($this->connectTimer !== false) {
            Timer::del($this->connectTimer);
            $this->connectTimer = false;
        }
    }

    // ─── 结果集解析工具 ───────────────────────────────────

    private function parseColumnDef(string $pkt): ?array
    {
        $pos = 0;
        $this->readLenEncodedStr($pkt, $pos);      // catalog
        $schema = $this->readLenEncodedStr($pkt, $pos);
        $table = $this->readLenEncodedStr($pkt, $pos);
        $this->readLenEncodedStr($pkt, $pos);      // org_table
        $name = $this->readLenEncodedStr($pkt, $pos);
        $this->readLenEncodedStr($pkt, $pos);      // org_name
        $pos += 1;                                  // 固定字段长度（0x0c）
        if ($pos + 7 > strlen($pkt)) {
            return null;
        }
        $charset = unpack('v', substr($pkt, $pos, 2));
        $pos += 2;
        $maxLen = unpack('V', substr($pkt, $pos, 4));
        $pos += 4;
        $type = ord($pkt[$pos]);
        $pos += 1;
        $flags = unpack('v', substr($pkt, $pos, 2));
        $pos += 2;
        $decimals = ord($pkt[$pos]);

        return [
            'name' => $name,
            'type' => (string) $type,
            'schema' => $schema,
            'table' => $table,
            'charset' => (int) ($charset[1] ?? 0),
            'maxLen' => (int) ($maxLen[1] ?? 0),
            'flags' => (int) ($flags[1] ?? 0),
            'decimals' => $decimals,
        ];
    }

    private function parseRow(string $pkt, int $fieldCount): ?array
    {
        $pos = 0;
        $pktLen = strlen($pkt);
        $row = [];
        for ($i = 0; $i < $fieldCount; $i++) {
            if ($pos >= $pktLen) {
                return null;
            }
            $first = ord($pkt[$pos]);
            if ($first === self::PKT_LOCAL_INFILE) {
                // 0xFB = NULL 值
                $row[] = null;
                $pos += 1;
                continue;
            }
            $consumed = 0;
            $valLen = $this->decodeLengthEncodedIntAt($pkt, $pos, $consumed);
            $pos += $consumed;
            if ($valLen === null) {
                return null;
            }
            $row[] = substr($pkt, $pos, $valLen);
            $pos += $valLen;
        }
        return $row;
    }

    private function decodeLengthEncodedIntAt(string $pkt, int $pos, int &$consumed): ?int
    {
        $consumed = 0;
        if ($pos >= strlen($pkt)) {
            return null;
        }
        $first = ord($pkt[$pos]);
        if ($first < 0xFB) {            // 0-250
            $consumed = 1;
            return $first;
        }
        if ($first === 0xFC) {          // 2 字节
            if (strlen($pkt) < $pos + 3) {
                return null;
            }
            $val = unpack('v', substr($pkt, $pos + 1, 2));
            $consumed = 3;
            return (int) ($val[1] ?? 0);
        }
        if ($first === 0xFD) {          // 3 字节
            if (strlen($pkt) < $pos + 4) {
                return null;
            }
            $val = unpack('V', substr($pkt, $pos + 1, 3) . "\0");
            $consumed = 4;
            return (int) ($val[1] ?? 0);
        }
        if ($first === 0xFE) {          // 8 字节
            if (strlen($pkt) < $pos + 9) {
                return null;
            }
            $v1 = unpack('V', substr($pkt, $pos + 1, 4));
            $v2 = unpack('V', substr($pkt, $pos + 5, 4));
            $consumed = 9;
            return ((int) ($v2[1] ?? 0)) * 4294967296 + (int) ($v1[1] ?? 0);
        }
        return null;                    // 0xFB 为 NULL，不在本函数处理
    }

    private function readLenEncodedStr(string $pkt, int &$pos): string
    {
        $consumed = 0;
        $len = $this->decodeLengthEncodedIntAt($pkt, $pos, $consumed);
        $pos += $consumed;
        if ($len === null || $len <= 0 || $pos + $len > strlen($pkt)) {
            return '';
        }
        $str = substr($pkt, $pos, $len);
        $pos += $len;
        return $str;
    }

    private function encLen(int $len): string
    {
        if ($len < 252) {
            return chr($len);
        }
        if ($len < 65536) {
            return chr(0xFC) . pack('v', $len);
        }
        if ($len < 4294967296) {
            return chr(0xFD) . pack('V', $len);
        }
        return chr(0xFE) . pack('V', $len) . pack('N', 0);
    }

    private function parseErrorMessage(string $pkt): string
    {
        if ($pkt === '') {
            return '未知错误';
        }
        $pos = 1;
        $code = unpack('v', substr($pkt, $pos, 2));
        $pos += 2;
        $sqlState = substr($pkt, $pos, 5);
        $pos += 5;
        $msg = substr($pkt, $pos);
        return 'MySQL ' . (int) ($code[1] ?? 0) . ' [' . $sqlState . '] ' . $msg;
    }

    // ─── 只读访问器（调试/测试用） ────────────────────────

    public function getState(): string
    {
        return $this->state;
    }

    public function getServerVersion(): string
    {
        return $this->serverVersion;
    }

    public function getConnectionId(): int
    {
        return $this->connectionId;
    }

    public function isConnected(): bool
    {
        return $this->conn !== null && $this->state === self::ST_IDLE;
    }

    /** 连接是否存活（TCP 未断；含 busy 状态——dump 期间查询仍可用独立实例，此判断用于复用探测） */
    public function isAlive(): bool
    {
        return $this->conn !== null && ($this->state === self::ST_IDLE || $this->state === self::ST_RESULT);
    }

    /** 认证时携带的库（query 连接按库判重复用；空串=无默认库） */
    public function getCurrentDatabase(): string
    {
        return $this->authDatabase;
    }
}
