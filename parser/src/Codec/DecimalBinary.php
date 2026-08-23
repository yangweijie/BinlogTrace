<?php

declare(strict_types=1);

namespace Typephp\BinlogParser\Codec;

/** 解析 MySQL NEWDECIMAL 二进制格式（binlog 列值）→ 十进制字符串。
 *
 * 格式：高位字节最高 bit 为符号位；每 4 字节压缩 9600 范围（2 位/字节），
 * 不足 4 字节时字节数 = ceil(digits*0.5)+1 并左对齐。
 * 参考：https://dev.mysql.com/doc/internals/newdecimal-byte-order.html
 */
final class DecimalBinary
{
    /** 解码 NEWDECIMAL 字节 → 字符串 */
    public static function decode(string $bytes, int $precision, int $scale): string
    {
        if (strlen($bytes) === 0) {
            return '0';
        }

        $neg = (ord($bytes[0]) & 0x80) !== 0;
        $bytes[0] = chr(ord($bytes[0]) & 0x7F);

        $intDigits = $precision - $scale;
        $fracDigits = $scale;

        $intBytes = intdiv($intDigits, 2) * 4 + self::uncompressedBytes($intDigits % 2);
        $fracBytes = intdiv($fracDigits, 2) * 4 + self::uncompressedBytes($fracDigits % 2);

        $intStr = self::decodeDecimalPart($bytes, 0, $intBytes, $intDigits);
        $fracStr = '';
        if ($fracDigits > 0) {
            $fracStr = self::decodeDecimalPart($bytes, $intBytes, $fracBytes, $fracDigits);
            $fracStr = str_pad($fracStr, $fracDigits, '0', STR_PAD_RIGHT);
        }

        $result = $intStr;
        if ($fracDigits > 0) {
            $result .= '.' . $fracStr;
        }
        if ($neg && $result !== '0') {
            $intZero = ($intStr === '0');
            $fracZero = ($fracDigits > 0 && self::isZero($fracStr));
            if (!($intZero && $fracZero)) {
                $result = '-' . $result;
            }
        }
        return $result;
    }

    /** 计算不足 4 字节（未压缩）的字节数 */
    private static function uncompressedBytes(int $digits): int
    {
        return ($digits === 0) ? 0 : (int)ceil($digits * 0.5) + 1;
    }

    /** 解码一个 decimal 部分（整数或小数）的字节序列 */
    private static function decodeDecimalPart(string $bytes, int $start, int $byteLen, int $digitLen): string
    {
        $result = '';
        $pos = $start;
        $remaining = $byteLen;

        while ($remaining > 0) {
            $chunkBytes = ($remaining >= 4) ? 4 : $remaining;
            $chunkDigits = $chunkBytes * 2;
            $left = $digitLen - strlen($result);
            if ($chunkDigits > $left) {
                $chunkDigits = $left;
            }

            $val = 0;
            for ($i = 0; $i < $chunkBytes; $i++) {
                $val = ($val << 8) | ord($bytes[$pos + $i]);
            }

            $partStr = '';
            for ($d = 0; $d < $chunkDigits; $d++) {
                $partStr = ($val % 100) . $partStr;
                $val = intdiv($val, 100);
            }
            $partStr = str_pad($partStr, $chunkDigits, '0', STR_PAD_LEFT);
            $result .= $partStr;

            $remaining -= $chunkBytes;
            $pos += $chunkBytes;
            if ($remaining > 0 && $remaining < 4) {
                break;
            }
        }

        return substr($result, 0, $digitLen);
    }

    private static function isZero(string $s): bool
    {
        for ($i = 0; $i < strlen($s); $i++) {
            if ($s[$i] !== '0') {
                return false;
            }
        }
        return true;
    }
}