<?php
declare(strict_types=1);

final class Protocol
{
    private $stream = null;

    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    public function readPacket(): string|false
    {
        if ($this->stream === null) {
            return false;
        }
        return fread($this->stream, 4096);
    }

    public function writeComPacket(string $packet): bool
    {
        if ($this->stream === null) {
            return false;
        }
        return fwrite($this->stream, $packet) !== false;
    }

    public function encLen(int $len): string
    {
        if ($len < 251) {
            return chr($len);
        }
        return chr(252) . pack('v', $len);
    }

    public function decodeLengthEncodedInt(string $pkt): int
    {
        $first = ord($pkt[0]);
        if ($first < 251) {
            return $first;
        }
        return (int)unpack('v', substr($pkt, 1, 2))[1];
    }

    public function readColumnDef(): array|false
    {
        return ['name' => 'column', 'type' => 'string'];
    }

    public function parseRow(string $rowPkt, int $fieldCount): array|false
    {
        return [];
    }
}
