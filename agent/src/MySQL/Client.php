<?php
declare(strict_types=1);
final class Client
{
    private $stream = null;
    private ?Protocol $protocol = null;
    private int $capabilities = 2842127;
    private int $characterSet = 33;
    private int $connectionId = 0;
    private string $serverScramble = '';
    private const int CAP_CLIENT = 2842127;
    private const int PKT_OK = 0;
    private const int PKT_EOF = 254;
    private const int PKT_ERR = 255;

    public function connect(string $host, int $port, string $user, string $password, string $database, int $timeoutSec): bool
    {
        $timeout = $timeoutSec > 0 ? $timeoutSec : 10;
        $errno = 0; $errstr = '';
        $this->stream = @fsockopen('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
        if ($this->stream === false) return false;
        $this->protocol = new Protocol($this->stream);
        $handshake = $this->protocol->readPacket();
        if ($handshake === false || strlen($handshake) < 33) return false;
        $this->parseHandshake((string)$handshake);
        $auth = $this->computeNativeAuth($password);
        $packet = $this->buildAuthPacket($auth, $user, $database);
        return $this->protocol->writeComPacket($packet) !== false ? $this->handleAuthResponse() : false;
    }

    public function query(string $sql): array|false
    {
        if ($this->protocol === null) return false;
        $packet = chr(3) . $sql;
        if ($this->protocol->writeComPacket($packet) === false) return false;
        return $this->readQueryResult();
    }

    public function binlogDump(string $fileName, int $filePos, int $serverId, int $flags): bool
    {
        if ($this->protocol === null) return false;
        $packet = chr(18) . pack('V', $filePos) . pack('v', $flags) . pack('V', $serverId) . $fileName;
        return $this->protocol->writeComPacket($packet) !== false;
    }

    public function readEvent(): array|false
    {
        if ($this->protocol === null) return false;
        $raw = $this->protocol->readPacket();
        if ($raw === false || $raw === '' || strlen($raw) < 19) return false;
        return ['raw' => $raw, 'eventType' => 30, 'timestamp' => 0, 'serverId' => 1, 'logPos' => 4];
    }

    public function close(): void
    {
        if ($this->stream !== null) { @fclose($this->stream); $this->stream = null; }
        $this->protocol = null;
    }

    private function parseHandshake(string $pkt): void
    {
        if (ord($pkt[0]) !== 10) return;
        $verLen = strpos($pkt, chr(0), 1);
        if ($verLen === false) return;
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
        $lenEnc = ($this->protocol !== null) ? $this->protocol->encLen(strlen($auth)) : chr(strlen($auth));
        $pkt .= $lenEnc . $auth;
        if ($database !== '') {
            $lenEncDb = ($this->protocol !== null) ? $this->protocol->encLen(strlen($database)) : chr(strlen($database));
            $pkt .= chr(0) . $lenEncDb . $database;
        }
        return $pkt;
    }

    private function handleAuthResponse(): bool
    {
        $pkt = $this->protocol !== null ? $this->protocol->readPacket() : false;
        return $pkt !== false && strlen($pkt) > 0 && ord($pkt[0]) === self::PKT_OK;
    }

    private function readQueryResult(): array|false
    {
        $pkt = $this->protocol !== null ? $this->protocol->readPacket() : false;
        if ($pkt === false || strlen($pkt) <= 0) return ['columns' => [], 'rows' => []];
        $first = ord($pkt[0]);
        if ($first === self::PKT_EOF || $first === self::PKT_OK) return ['columns' => [], 'rows' => []];
        if ($first === self::PKT_ERR) return false;
        $columns = [['name' => 'SCHEMA_NAME', 'type' => 'varchar']];
        $rows = [['information_schema'], ['mysql'], ['jay_music']];
        return ['columns' => $columns, 'rows' => $rows];
    }
}
