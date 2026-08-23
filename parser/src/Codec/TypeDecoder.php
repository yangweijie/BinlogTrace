<?php

declare(strict_types=1);

namespace Typephp\BinlogParser\Codec;

use Typephp\BinlogParser\Codec\DecimalBinary;
use Typephp\BinlogParser\Codec\DateTimeBinary;

/** 按 MySQL 列类型解码单个非空值（AC-12：值统一字符串化）。
 *
 * 返回 ['value' => string|null, 'consumed' => int]。
 * meta 由 TABLE_MAP 事件提供（bitCount/precision/scale/fracDigits/values/length）。
 */
final class TypeDecoder
{
    public static function decode(string $bytes, int $offset, int $mysqlType, array $meta = []): array
    {
        $len = strlen($bytes);
        if ($offset >= $len) {
            return ['value' => null, 'consumed' => 0];
        }

        // ---- 定长数值 ----
        if ($mysqlType === 1) { // TINY
            $v = ord($bytes[$offset]);
            return ['value' => (string)$v, 'consumed' => 1];
        }
        if ($mysqlType === 9) { // INT24
            $b = LengthCoded::readBytesN($bytes, $offset, 3);
            if ($b['consumed'] === 0) { return ['value' => '0', 'consumed' => 0]; }
            $v = ord($b['value'][0]) | (ord($b['value'][1]) << 8) | (ord($b['value'][2]) << 16);
            return ['value' => (string)$v, 'consumed' => 3];
        }
        if ($mysqlType === 3) { // SHORT
            $r = LengthCoded::readUint16LE($bytes, $offset);
            return ['value' => (string)$r['value'], 'consumed' => $r['consumed']];
        }
        if ($mysqlType === 4) { // LONG
            $r = LengthCoded::readUint32LE($bytes, $offset);
            return ['value' => (string)$r['value'], 'consumed' => $r['consumed']];
        }
        if ($mysqlType === 8) { // LONGLONG
            $r = LengthCoded::readUint64LE($bytes, $offset);
            return ['value' => (string)$r['value'], 'consumed' => $r['consumed']];
        }
        if ($mysqlType === 5) { // FLOAT
            $b = LengthCoded::readBytesN($bytes, $offset, 4);
            if ($b['consumed'] === 0) { return ['value' => '0', 'consumed' => 0]; }
            $vals = unpack('f', $b['value']);
            return ['value' => (string)($vals[1] ?? 0.0), 'consumed' => 4];
        }
        if ($mysqlType === 6) { // DOUBLE
            $b = LengthCoded::readBytesN($bytes, $offset, 8);
            if ($b['consumed'] === 0) { return ['value' => '0', 'consumed' => 0]; }
            $vals = unpack('d', $b['value']);
            return ['value' => (string)($vals[1] ?? 0.0), 'consumed' => 8];
        }
        if ($mysqlType === 11) { // YEAR
            $v = ord($bytes[$offset]);
            $year = ($v === 0) ? 0 : ($v + 1900);
            return ['value' => (string)$year, 'consumed' => 1];
        }
        if ($mysqlType === 12) { // NEWDATE
            $r = LengthCoded::readUint32LE($bytes, $offset);
            return ['value' => DateTimeBinary::mysqlDaysToDate((int)$r['value']), 'consumed' => 4];
        }
        if ($mysqlType === 7 && !isset($meta['fracDigits'])) { // TIMESTAMP (4B unix)
            $r = LengthCoded::readUint32LE($bytes, $offset);
            return ['value' => (string)(int)$r['value'], 'consumed' => 4];
        }

        // ---- 变长（length-encoded）----
        if (in_array($mysqlType, [17, 249, 250, 251, 15, 255], true)) {
            $r = LengthCoded::readBytes($bytes, $offset);
            if ($r['consumed'] === 0) { return ['value' => null, 'consumed' => 0]; }
            return ['value' => $r['value'], 'consumed' => $r['consumed']];
        }
        if ($mysqlType === 247) { // ENUM
            $r = LengthCoded::readBytes($bytes, $offset);
            if ($r['consumed'] === 0) { return ['value' => '', 'consumed' => 0]; }
            $idx = ord($r['value'][0]);
            if ($idx === 0) { return ['value' => '', 'consumed' => $r['consumed']]; }
            $vals = (array)($meta['values'] ?? []);
            if ($idx <= count($vals)) {
                return ['value' => (string)$vals[$idx - 1], 'consumed' => $r['consumed']];
            }
            return ['value' => (string)$idx, 'consumed' => $r['consumed']];
        }
        if ($mysqlType === 248) { // SET
            $r = LengthCoded::readBytes($bytes, $offset);
            if ($r['consumed'] === 0) { return ['value' => '', 'consumed' => 0]; }
            $bits = 0;
            $n = strlen($r['value']);
            for ($i = 0; $i < $n; $i++) { $bits |= (int)ord($r['value'][$i]) << ($i * 8); }
            $vals = (array)($meta['values'] ?? []);
            $parts = [];
            $bit = 1;
            foreach ($vals as $val) {
                if (($bits & $bit) !== 0) { $parts[] = (string)$val; }
                $bit <<= 1;
            }
            return ['value' => implode(',', $parts), 'consumed' => $r['consumed']];
        }
        if ($mysqlType === 16) { // BIT
            $r = LengthCoded::readBytes($bytes, $offset);
            if ($r['consumed'] === 0) { return ['value' => '0', 'consumed' => 0]; }
            $bitCount = (int)($meta['bitCount'] ?? 8);
            $val = 0;
            $n = strlen($r['value']);
            for ($i = 0; $i < $n; $i++) { $val |= (int)ord($r['value'][$i]) << ($i * 8); }
            $mask = (1 << $bitCount) - 1;
            return ['value' => (string)($val & $mask), 'consumed' => $r['consumed']];
        }
        if ($mysqlType === 246) { // NEWDECIMAL
            $r = LengthCoded::readBytes($bytes, $offset);
            if ($r['consumed'] === 0) { return ['value' => '0', 'consumed' => 0]; }
            $precision = (int)($meta['precision'] ?? 10);
            $scale = (int)($meta['scale'] ?? 0);
            return [
                'value' => DecimalBinary::decode($r['value'], $precision, $scale),
                'consumed' => $r['consumed'],
            ];
        }

        // ---- 日期时间变体 ----
        if ($mysqlType === 7 && isset($meta['fracDigits'])) { // DATETIME2
            return DateTimeBinary::decodeDatetime2($bytes, $offset, (int)$meta['fracDigits']);
        }
        if ($mysqlType === 13) { // TIMESTAMP2
            return DateTimeBinary::decodeTimestamp2($bytes, $offset, (int)($meta['fracDigits'] ?? 0));
        }
        if ($mysqlType === 14) { // TIME2
            return DateTimeBinary::decodeTime2($bytes, $offset, (int)($meta['fracDigits'] ?? 0));
        }

        // ---- STRING / VARCHAR / VARSTRING ----
        if ($mysqlType === 253 || $mysqlType === 254 || $mysqlType === 15) {
            return self::decodeVarString($bytes, $offset, $meta);
        }

        // ---- 兜底 ----
        $r = LengthCoded::readBytes($bytes, $offset);
        if ($r['consumed'] === 0) { return ['value' => null, 'consumed' => 0]; }
        return ['value' => $r['value'], 'consumed' => $r['consumed']];
    }

    private static function decodeVarString(string $bytes, int $offset, array $meta): array
    {
        $maxLen = (int)($meta['length'] ?? 255);
        $len = strlen($bytes);
        if ($maxLen <= 255) {
            if ($offset + 1 > $len) { return ['value' => '', 'consumed' => 0]; }
            $strLen = ord($bytes[$offset]);
            $off = $offset + 1;
            $hdr = 1;
        } else {
            if ($offset + 2 > $len) { return ['value' => '', 'consumed' => 0]; }
            $strLen = ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
            $off = $offset + 2;
            $hdr = 2;
        }
        if ($off + $strLen > $len) {
            return ['value' => '', 'consumed' => $hdr];
        }
        $s = substr($bytes, $off, $strLen);
        if ($meta[0] === 253) { // STRING: trim trailing NULs
            $s = rtrim($s, "\0");
        }
        return ['value' => $s, 'consumed' => $hdr + $strLen];
    }
}