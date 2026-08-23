<?php

declare(strict_types=1);

/**
 * Client — MySQL 协议客户端（高层 API）
 * 底层 packet 读写委托给 Protocol；这里只处理业务：连接、认证、查询、binlog-dump
 * 无闭包、无 generator、无引用 —— TypePHP 子集兼容
 */
final class Client
{
    private $stream = null;
    private ?Protocol $protocol = null;
    private int $capabilities = 0;
    private int $characterSet = 33;
    private int $connectionId = 0;
    private string $serverScramble = '';

    private const int CAP_CLIENT = 2842127;
    private const int PKT_OK = 0;
    private const int PKT_EOF = 254;
    private const int PKT_ERR = 255;

    /** 连接并认证 */
    public function connect(string $host, int $port, string $user, string $password, string $database, int $timeoutSec): bool
    {
        $errno = 0;
        $errstr = '';
        $timeout = $timeoutSec > 0 ? $timeoutSec : 10;
        $this->stream = @fsockopen('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
        if ($this->stream === false) {
            error_log('MySQL connect failed: ' . $errstr);
            return false;
        }
        $this->protocol = new Protocol($this->stream);

        $handshake = $this->protocol->readPacket();
        if ($handshake === false || strlen($handshake) < 33) {
            return false;
        }
        $this->parseHandshake((string)$handshake);

        $auth = $this->computeNativeAuth($password);
        $packet = $this->buildAuthPacket($auth, $user, $database);
        if ($this->protocol->writeComPacket($packet) === false) {
            return false;
        }
        return $this->handleAuthResponse();
    }

    /** 发送只读查询 */
    public function query(string $sql): array|false
    {
        if ($this->protocol === null) {
            return false;
        }
        $packet = chr(3) . $sql;
        if ($this->protocol->writeComPacket($packet) === false) {
            return false;
        }
        return $this->readQueryResult();
    }

    /** 发送 binlog-dump 命令 */
    public function binlogDump(string $fileName, int $filePos, int $serverId, int $flags): bool
    {
        if ($this->protocol === null) {
            return false;
        }
        $packet = chr(18)
            . pack('V', $filePos)
            . pack('v', $flags)
            . pack('V', $serverId)
            . $fileName;
        return $this->protocol->writeComPacket($packet) !== false;
    }

    /** 读取一个 binlog 事件 */
    public function readEvent(): array|false
    {
        if ($this->protocol === null) {
            return false;
        }
        $raw = $this->protocol->readPacket();
        if ($raw === false || $raw === '') {
            return false;
        }
        if (strlen($raw) < 19) {
            return false;
        }
        $h = unpack('Vtimestamp/Ctype/VserverId/VeventSize/VlogPos/vflags', substr($raw, 0, 19));
        return [
            'raw' => $raw,
            'eventType' => (int)$h['type'],
            'timestamp' => (int)$h['timestamp'],
            'serverId' => (int)$h['serverId'],
            'logPos' => (int)$h['logPos'],
        ];
    }

    public function getStream()
    {
        return $this->stream;
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
        $this->protocol = null;
    }

    // ─── Handshake ─────────────────────────────────────────

    private function parseHandshake(string $pkt): void
    {
        if (ord($pkt[0]) !== 10) {
            return;
        }
        $verLen = strpos($pkt, chr(0), 1);
        if ($verLen === false) {
            return;
        }
        $connId = unpack('V', substr($pkt, $verLen + 1, 4));
        $this->connectionId = (int)($connId[1] ?? 0);

        $authData1 = substr($pkt, $verLen + 5, 8);
        $lowCap = unpack('v', substr($pkt, $verLen + 14, 2));
        $this->characterSet = ord($pkt[$verLen + 16]);
        $highCap = unpack('v', substr($pkt, $verLen + 19, 2));
        $this->capabilities = ((int)($highCap[1] ?? 0) << 16) | (int)($lowCap[1] ?? 0);
        $authDataLen = ord($pkt[$verLen + 21]);

        if ($authDataLen > 8) {
            $authData2Start = $verLen + 22 + 6;
            $authData2 = substr($pkt, $authData2Start, $authDataLen - 8);
            $this->serverScramble = $authData1 . $authData2;
        } else {
            $this->serverScramble = $authData1;
        }
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

    private function buildAuthPacket(string $auth, string $user, string $database): string
    {
        $pkt = pack('V', self::CAP_CLIENT) . pack('V', 16777215) . chr($this->characterSet);
        $pkt .= str_repeat(chr(0), 23);
        $pkt .= $user . chr(0);
        $pkt .= $this->protocol->encLen(strlen($auth)) . $auth;
        if ($database !== '') {
            $pkt .= chr(0) . $this->protocol->encLen(strlen($database)) . $database;
        }
        return $pkt;
    }

    private function handleAuthResponse(): bool
    {
        $pkt = $this->protocol->readPacket();
        if ($pkt === false) {
            return false;
        }
        if (strlen($pkt) <= 0) {
            return false;
        }
        $first = ord($pkt[0]);
        if ($first === self::PKT_OK) {
            return true;
        }
        if ($first === self::PKT_ERR) {
            error_log('MySQL auth failed: ' . $this->parseErrorMessage((string)$pkt));
            return false;
        }
        return false;
    }

    // ─── Query 结果集 ──────────────────────────────────────

    private function readQueryResult(): array|false
    {
        $pkt = $this->protocol->readPacket();
        if ($pkt === false) {
            return false;
        }
        if (strlen($pkt) <= 0) {
            return ['columns' => [], 'rows' => []];
        }
        $first = ord($pkt[0]);
        if ($first === self::PKT_EOF || $first === self::PKT_OK) {
            return ['columns' => [], 'rows' => []];
        }
        if ($first === self::PKT_ERR) {
            return false;
        }

        $fieldCount = $this->protocol->decodeLengthEncodedInt((string)$pkt);
        if ($fieldCount <= 0) {
            return ['columns' => [], 'rows' => []];
        }

        $columns = [];
        for ($i = 0; $i < $fieldCount; $i++) {
            $col = $this->protocol->readColumnDef();
            if ($col !== false) {
                $columns[] = $col;
            }
        }

        $this->protocol->readPacket();
        $rows = [];
        while (true) {
            $rowPkt = $this->protocol->readPacket();
            if ($rowPkt === false) {
                break;
            }
            if (strlen($rowPkt) <= 1 && (ord($rowPkt[0]) === self::PKT_EOF || ord($rowPkt[0]) === self::PKT_OK)) {
                break;
            }
            $row = $this->protocol->parseRow((string)$rowPkt, $fieldCount);
            if ($row !== false) {
                $rows[] = $row;
            }
        }
        return ['columns' => $columns, 'rows' => $rows];
    }

    private function parseErrorMessage(string $pkt): string
    {
        $pos = 1;
        $code = unpack('v', substr($pkt, $pos, 2));
        $pos += 2;
        $sqlState = substr($pkt, $pos, 5);
        $pos += 5;
        $msg = substr($pkt, $pos);
        return 'MySQL ' . (int)($code[1] ?? 0) . ' [' . $sqlState . '] ' . $msg;
    }
}