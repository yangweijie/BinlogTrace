<?php

declare(strict_types=1);

namespace Typephp\BinlogParser\Codec;

/** 读取 MySQL length-coded 编码的整数与字节串（LE 字节序） */
final class LengthCoded
{
    public static function readInt(string $buf, int $offset = 0): array
    {
        $len = strlen($buf);
        if ($offset >= $len) {
            return ['value' => 0, 'consumed' => 0];
        }
        $b = ord($buf[$offset]);
        if ($b < 252) {
            return ['value' => $b, 'consumed' => 1];
        }
        if ($b === 252) {
            if ($offset + 3 > $len) { return ['value' => 0, 'consumed' => 0]; }
            return ['value' => (int)self::u16($buf, $offset + 1), 'consumed' => 3];
        }
        if ($b === 253) {
            if ($offset + 4 > $len) { return ['value' => 0, 'consumed' => 0]; }
            return ['value' => (int)self::u24($buf, $offset + 1), 'consumed' => 4];
        }
        // 254 -> 8-byte uint64 (LE)
        if ($offset + 9 > $len) { return ['value' => 0, 'consumed' => 0]; }
        $lo = self::u32($buf, $offset + 1);
        $hi = self::u32($buf, $offset + 5);
        return ['value' => $lo + ($hi * 4294967296), 'consumed' => 9];
    }

    public static function readBytes(string $buf, int $offset = 0): array
    {
        $r = self::readInt($buf, $offset);
        if ($r['consumed'] === 0) {
            return ['value' => '', 'consumed' => 0];
        }
        $start = $offset + $r['consumed'];
        $n = (int)$r['value'];
        if ($start + $n > strlen($buf)) {
            return ['value' => '', 'consumed' => 0];
        }
        return ['value' => substr($buf, $start, $n), 'consumed' => $r['consumed'] + $n];
    }

    public static function readUint16LE(string $buf, int $offset = 0): array
    {
        return ['value' => (int)self::u16($buf, $offset), 'consumed' => 2];
    }

    public static function readUint32LE(string $buf, int $offset = 0): array
    {
        return ['value' => (int)self::u32($buf, $offset), 'consumed' => 4];
    }

    public static function readUint64LE(string $buf, int $offset = 0): array
    {
        $lo = self::u32($buf, $offset);
        $hi = self::u32($buf, $offset + 4);
        return ['value' => $lo + ($hi * 4294967296), 'consumed' => 8];
    }

    public static function readBytesN(string $buf, int $offset, int $n): array
    {
        if ($offset + $n > strlen($buf)) {
            return ['value' => '', 'consumed' => 0];
        }
        return ['value' => substr($buf, $offset, $n), 'consumed' => $n];
    }

    private static function u16(string $buf, int $o): int
    {
        return ord($buf[$o]) | (ord($buf[$o + 1]) << 8);
    }

    private static function u24(string $buf, int $o): int
    {
        return ord($buf[$o]) | (ord($buf[$o + 1]) << 8) | (ord($buf[$o + 2]) << 16);
    }

    private static function u32(string $buf, int $o): int
    {
        return ord($buf[$o])
            | (ord($buf[$o + 1]) << 8)
            | (ord($buf[$o + 2]) << 16)
            | (ord($buf[$o + 3]) << 24);
    }
}
