<?php

declare(strict_types=1);

/**
 * Protocol — MySQL 协议传输层（packet 读写 + length-encoded + 结果集辅助）
 * 无状态共享：不持有业务数据，只处理字节流
 */
final class Protocol
{
    private $stream;
    private int $seq = 0;

    public function __construct($stream)
    {
        $this->stream = $stream;
        $this->seq = 0;
    }

    // ─── Packet 读写 ─────────────────────────────────────

    public function readPacket(): string|false
    {
        $header = fread($this->stream, 4);
        if ($header === false || $header === '') {
            return false;
        }
        if (strlen($header) < 4) {
            return false;
        }
        $len = unpack('V', $header . chr(0));
        $payloadLen = (int)$len[1];
        $this->seq = (int)unpack('C', substr($header, 3, 1))[1];

        if ($payloadLen <= 0) {
            return '';
        }

        $data = '';
        while (strlen($data) < $payloadLen) {
            $chunk = fread($this->stream, $payloadLen - strlen($data));
            if ($chunk === false || $chunk === '') {
                return false;
            }
            $data .= $chunk;
        }

        if ($payloadLen === 16777215) {
            return $data . $this->readFragment();
        }
        return $data;
    }

    public function readFragment(): string
    {
        $data = '';
        while (true) {
            $header = fread($this->stream, 4);
            if ($header === false || $header === '') {
                break;
            }
            $len = unpack('V', $header . chr(0));
            $payloadLen = (int)$len[1];
            $this->seq = (int)unpack('C', substr($header, 3, 1))[1];
            if ($payloadLen <= 0) {
                break;
            }
            $chunk = '';
            while (strlen($chunk) < $payloadLen) {
                $c = fread($this->stream, $payloadLen - strlen($chunk));
                if ($c === false || $c === '') {
                    return $data;
                }
                $chunk .= $c;
            }
            $data .= $chunk;
            if ($payloadLen < 16777215) {
                break;
            }
        }
        return $data;
    }

    public function writeComPacket(string $pkt): bool
    {
        $header = pack('V', strlen($pkt)) . chr($this->seq);
        $this->seq = ($this->seq + 1) & 255;
        $out = $header . $pkt;
        $written = 0;
        while ($written < strlen($out)) {
            $w = @fwrite($this->stream, substr($out, $written));
            if ($w === false || $w === 0) {
                return false;
            }
            $written += $w;
        }
        return true;
    }

    // ─── Length-encoded 工具 ─────────────────────────────

    public function decodeLengthEncodedInt(string $pkt): int
    {
        $consume = 0;
        $result = $this->decodeLengthEncodedIntAt($pkt, 0, $consume);
        return $result ?? 0;
    }

    public function decodeLengthEncodedIntAt(string $pkt, int $pos, int &$consume): int|null
    {
        $consume = 0;
        if ($pos >= strlen($pkt)) {
            return null;
        }
        $first = ord($pkt[$pos]);
        if ($first < 252) {
            $consume = 1;
            return $first;
        }
        if ($first === 252) {
            $val = unpack('v', substr($pkt, $pos + 1, 2));
            $consume = 3;
            return (int)($val[1] ?? 0);
        }
        if ($first === 253) {
            $val = unpack('V', substr($pkt, $pos + 1, 4));
            $consume = 5;
            return (int)($val[1] ?? 0);
        }
        $v1 = unpack('V', substr($pkt, $pos + 1, 4));
        $v2 = unpack('V', substr($pkt, $pos + 5, 4));
        $consume = 9;
        return ((int)($v2[1] ?? 0)) * 4294967296 + (int)($v1[1] ?? 0);
    }

    public function encLen(int $len): string
    {
        if ($len < 252) {
            return chr($len);
        }
        if ($len < 65536) {
            return chr(252) . pack('v', $len);
        }
        if ($len < 4294967296) {
            return chr(253) . pack('V', $len);
        }
        return chr(254) . pack('V', $len) . pack('N', 0);
    }

    public function readLenStr(string $pkt, int &$pos): string
    {
        $consume = 0;
        $len = $this->decodeLengthEncodedIntAt($pkt, $pos, $consume);
        $pos += $consume;
        if ($len === null || $len === 0) {
            return '';
        }
        $str = substr($pkt, $pos, $len);
        $pos += $len;
        return $str;
    }

    // ─── 结果集辅助 ──────────────────────────────────────

    public function readColumnDef(): array|false
    {
        $pkt = $this->readPacket();
        if ($pkt === false || $pkt === '') {
            return false;
        }
        $pos = 0;
        $catalog = $this->readLenStr($pkt, $pos);
        $schema = $this->readLenStr($pkt, $pos);
        $table = $this->readLenStr($pkt, $pos);
        $orgTable = $this->readLenStr($pkt, $pos);
        $name = $this->readLenStr($pkt, $pos);
        $orgName = $this->readLenStr($pkt, $pos);
        $pos += 1;
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
            'type' => (string)$type,
            'schema' => $schema,
            'table' => $table,
            'charset' => (int)($charset[1] ?? 0),
            'maxLen' => (int)($maxLen[1] ?? 0),
            'flags' => (int)($flags[1] ?? 0),
            'decimals' => $decimals,
        ];
    }

    public function parseRow(string $pkt, int $fieldCount): array|false
    {
        $pos = 0;
        $consume = 0;
        $row = [];
        for ($i = 0; $i < $fieldCount; $i++) {
            if ($pos >= strlen($pkt)) {
                return false;
            }
            $len = $this->decodeLengthEncodedIntAt($pkt, $pos, $consume);
            $pos += $consume;
            if ($len === null || $len === 0) {
                $row[] = '';
            } else {
                $row[] = substr($pkt, $pos, $len);
                $pos += $len;
            }
        }
        return $row;
    }
}