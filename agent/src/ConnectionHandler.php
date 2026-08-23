<?php

declare(strict_types=1);

/**
 * ConnectionHandler — 单浏览器连接全生命周期
 * 处理 WS 帧（connect/binlog-dump/query/close/heartbeat）→ MySQL 交互 → 事件透传
 * 事件循环用 stream_select 复用浏览器/MySQL 双流，15s 无数据发心跳
 */
final class ConnectionHandler
{
    private $wsStream;
    private Client $mysql;
    private MetaGatherer $meta;
    private bool $dumping = false;
    private bool $connected = false;
    private int $serverId;
    private string $binlogFile = '';
    private int $lastHeartbeatTs = 0;
    private int $eventCount = 0;

    public function __construct($wsStream)
    {
        $this->wsStream = $wsStream;
        $this->mysql = new Client();
        $this->meta = new MetaGatherer($this->mysql, 0);
        $this->serverId = random_int(1, 2147483647);
        echo '[agent] ConnectionHandler constructed, serverId=' . $this->serverId . PHP_EOL;
        // 注意：此处不设置非阻塞。doHandshake() 需要在阻塞模式下完成 HTTP 升级读取。
        // 切换非阻塞在 run() 中握手成功后进行。
    }

    public function run(): void
    {
        echo '[agent] run(): starting handshake' . PHP_EOL;
        if (WsFrameCodec::doHandshake($this->wsStream) === false) {
            return;
        }
        // 用 stream_set_timeout 替代 stream_select（TypePHP 的 stream_select 有 bug）
        stream_set_timeout($this->wsStream, AgentConstants::HEARTBEAT_INTERVAL_MS / 1000);
        $this->lastHeartbeatTs = $this->now();
        while (true) {
            if ($this->dispatchAndHeartbeat() === false) {
                break;
            }
        }
        $this->cleanup();
    }

    /**
     * 用 stream_set_timeout + fread 替代 stream_select
     * fread 在阻塞模式下等待数据或超时
     * 超时后发送心跳
     */
    private function dispatchAndHeartbeat(): bool
    {
        echo '[agent] dispatchAndHeartbeat: reading WS frame' . PHP_EOL;
        $frame = WsFrameCodec::readFrame($this->wsStream);
        if ($frame === false || !is_array($frame)) {
            // 可能是超时或 EOF，检查 stream 状态
            $meta = stream_get_meta_data($this->wsStream);
            echo '[agent] dispatchAndHeartbeat: readFrame failed, timed_out=' . ($meta['timed_out'] ? 'true' : 'false') . ' feof=' . (feof($this->wsStream) ? 'true' : 'false') . PHP_EOL;
            if ($meta['timed_out'] && $this->dumping) {
                echo '[agent] dispatchAndHeartbeat: sending heartbeat (timeout)' . PHP_EOL;
                $this->sendHeartbeat();
                return true;
            }
            return false;
        }
        echo '[agent] dispatchAndHeartbeat: opcode=' . (int)$frame['opcode'] . ', payloadLen=' . strlen((string)$frame['payload']) . PHP_EOL;
        $opcode = (int)$frame['opcode'];
        $payload = (string)$frame['payload'];
        if ($opcode === 8) {
            return false;
        }
        if ($opcode === 9 || $opcode === 10) {
            WsFrameCodec::writePong($this->wsStream, $payload);
            return true;
        }
        if ($opcode === 1) {
            try {
                $this->dispatchJson($payload);
            } catch (\Throwable $e) {
                echo '[agent] dispatchAndHeartbeat: EXCEPTION ' . get_class($e) . ' ' . $e->getMessage() . PHP_EOL;
                return false;
            }
            return true;
        }
        return true;
    }

    private function dispatchJson(string $json): void
    {
        echo '[agent] dispatchJson: json_len=' . strlen($json) . PHP_EOL;
        $frame = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        echo '[agent] dispatchJson: decoded ok' . PHP_EOL;
        if (!is_array($frame) || !isset($frame['type'])) {
            $this->sendError('', AgentConstants::PROTOCOL_ERROR, '非 JSON 帧或缺少 type 字段');
            return;
        }
        $type = $frame['type'];
        $id = $frame['id'] ?? '';
        switch ($type) {
            case 'connect':
                $this->handleConnect($id, $frame['payload'] ?? []);
                break;
            case 'binlog-dump':
                $this->handleBinlogDump($id, $frame['payload'] ?? []);
                break;
            case 'query':
                $this->handleQuery($id, $frame['payload'] ?? []);
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

    private function handleConnect(string $id, array $payload): void
    {
        if ($this->connected) {
            $this->sendError($id, AgentConstants::INVALID_PARAM, '已连接，请先 close');
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
            $this->sendError($id, AgentConstants::INVALID_PARAM, 'host 不能为空');
            return;
        }
        $this->serverId = $clientId > 0 ? $clientId : $this->serverId;
        $this->meta = new MetaGatherer($this->mysql, $this->serverId);

        $ok = $this->mysql->connect($host, $port, $user, $password, $database, (int)($timeout / 1000));
        if ($ok === false) {
            $this->sendError($id, AgentConstants::AUTH_FAILED, 'MySQL 认证失败');
            return;
        }
        $this->connected = true;
        $meta = $this->meta->gatherBinlogMeta();
        if ($meta['hasBinlog'] === true) {
            $this->binlogFile = (string)($meta['binlogFile'] ?? '');
        }
        $this->sendFrame($id, 'connected', $meta);
    }

    private function handleBinlogDump(string $id, array $payload): void
    {
        if (!$this->connected) {
            $this->sendError($id, AgentConstants::PROXY_NOT_READY, '尚未连接 MySQL');
            return;
        }
        $fileName = (string)($payload['binlogFile'] ?? $this->binlogFile);
        $filePos = (int)($payload['binlogPos'] ?? 4);
        $flags = (int)($payload['slaveFlags'] ?? 0);

        if ($fileName === '') {
            $this->sendError($id, AgentConstants::INVALID_PARAM, 'binlogFile 不能为空');
            return;
        }
        $ok = $this->mysql->binlogDump($fileName, $filePos, $this->serverId, $flags);
        if ($ok === false) {
            $this->sendError($id, AgentConstants::BINLOG_POSITION_INVALID, 'binlog-dump 命令发送失败');
            return;
        }
        $this->dumping = true;
        $this->eventCount = 0;
        $this->lastHeartbeatTs = $this->now();
        $this->sendFrame($id, 'dump-started', ['binlogFile' => $fileName, 'binlogPos' => $filePos]);
    }

    private function relayEvents(): bool
    {
        $event = $this->mysql->readEvent();
        while (is_array($event)) {
            $eventType = (int)$event['eventType'];
            $raw = (string)$event['raw'];

            if (strlen($raw) >= 19) {
                $flags = unpack('v', substr($raw, 17, 2));
                if ((int)($flags[1] ?? 0) & 0x02) {
                    $this->sendError('', AgentConstants::TRANSACTION_COMPRESSED, 'MySQL 8.0.20+ 事务压缩事件无法解码');
                    $this->dumping = false;
                    return true;
                }
            }
            if ($eventType === 4) {
                $rotatePos = 23;
                if (strlen($raw) >= $rotatePos) {
                    $this->binlogFile = rtrim(substr($raw, $rotatePos), chr(0));
                }
            }

            $this->sendFrame('', 'binlog-event', [
                'raw' => base64_encode($raw),
                'eventType' => $eventType,
                'binlogFile' => $this->binlogFile,
                'binlogPos' => (int)$event['logPos'],
                'timestamp' => (int)$event['timestamp'],
                'serverId' => (int)$event['serverId'],
            ]);
            $this->eventCount++;
            $event = $this->mysql->readEvent();
        }
        return true;
    }

    private function handleQuery(string $id, array $payload): void
    {
        $sql = (string)($payload['sql'] ?? '');
        $trimmed = trim($sql);
        if (!preg_match('/^\s*(SELECT|SHOW)\b.*$/i', $trimmed)) {
            $this->sendError($id, AgentConstants::PROTOCOL_ERROR, '仅允许只读查询');
            return;
        }
        $result = $this->mysql->query($sql);
        if ($result === false) {
            $this->sendError($id, AgentConstants::PARSE_ERROR, '查询失败');
            return;
        }
        $colOut = [];
        foreach (($result['columns'] ?? []) as $col) {
            $colOut[] = ['name' => (string)($col['name'] ?? ''), 'type' => (string)($col['type'] ?? '')];
        }
        $this->sendFrame($id, 'query-result', ['columns' => $colOut, 'rows' => $result['rows'] ?? []]);
    }

    private function handleClose(): void
    {
        $this->dumping = false;
        $this->mysql->close();
        WsFrameCodec::writeClose($this->wsStream);
    }

    private function sendError(string $id, int $code, string $message): void
    {
        $this->sendFrame($id, 'error', ['code' => $code, 'message' => $message]);
    }

    private function sendHeartbeat(): void
    {
        $this->lastHeartbeatTs = $this->now();
        $this->sendFrame('', 'heartbeat', ['ts' => $this->lastHeartbeatTs, 'binlogPos' => null]);
    }

    private function sendFrame(string $id, string $type, array $payload): void
    {
        $json = json_encode([
            'v' => AgentConstants::PROTOCOL_VERSION,
            'id' => $id,
            'type' => $type,
            'ts' => $this->now(),
            'payload' => $payload,
        ], flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        WsFrameCodec::writeFrame($this->wsStream, 1, $json);
    }

    private function now(): int
    {
        return (int)(microtime(true) * 1000);
    }

    private function cleanup(): void
    {
        $this->mysql->close();
        try {
            @fclose($this->wsStream);
        } catch (\Throwable $e) {
            echo '[agent] cleanup: fclose wsStream error: ' . $e->getMessage() . PHP_EOL;
        }
    }
}