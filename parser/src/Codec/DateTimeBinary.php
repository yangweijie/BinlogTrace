<?php

declare(strict_types=1);

namespace Typephp\BinlogParser\Codec;

/** 解析 binlog 日期时间变体：DATETIME2 / TIMESTAMP2 / TIME2 + MySQL NEWDATE。
 *
 * 所有输出统一为字符串，保证精度（AC-12）。
 */
final class DateTimeBinary
{
    /** MySQL NEWDATE（天计数 → 日期字符串） */
    public static function mysqlDaysToDate(int $days): string
    {
        if ($days === 0) {
            return '0000-00-00';
        }
        $year = 0;
        $rem = $days;

        $n = intdiv($rem, 146097); $rem -= $n * 146097; $year += $n * 400;
        $n = intdiv($rem, 36524); if ($n === 4) { $n = 3; } $rem -= $n * 36524; $year += $n * 100;
        $n = intdiv($rem, 1461); if ($n === 25) { $n = 24; } $rem -= $n * 1461; $year += $n * 4;
        $n = intdiv($rem, 365); if ($n === 4) { $n = 3; } $rem -= $n * 365; $year += $n;

        $isLeap = ($year % 400 === 0) || (($year % 4 === 0) && ($year % 100 !== 0));
        $md = [31, ($isLeap ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $month = 1;
        $drem = $rem;
        for ($m = 0; $m < 12; $m++) {
            if ($drem < $md[$m]) { $month = $m + 1; break; }
            $drem -= $md[$m];
        }
        $day = $drem + 1;
        if ($day > 31) { $day = 31; }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** DATETIME2：5/6/7/8 字节。返回 [value, consumed]。 */
    public static function decodeDatetime2(string $bytes, int $offset, int $frac): array
    {
        $nb = self::datetime2Size($frac);
        $b = LengthCoded::readBytesN($bytes, $offset, $nb);
        if ($b['consumed'] === 0) {
            return ['value' => '0000-00-00 00:00:00', 'consumed' => 0];
        }
        $s = $b['value'];

        $ym = ord($s[0]) | (ord($s[1]) << 8);
        $year = $ym >> 4;
        $month = $ym & 0x0F;
        $day = ord($s[2]);
        $hour = ord($s[3]) >> 3;
        $minute = ((ord($s[3]) & 0x07) << 2) | (ord($s[4]) >> 6);
        $second = ord($s[4]) & 0x3F;

        $timeStr = sprintf('%02d:%02d:%02d', $hour, $minute, $second);
        if ($frac > 0) {
            $fv = self::readFrac($s, 5, $frac);
            $timeStr .= '.' . str_pad((string)$fv, $frac, '0', STR_PAD_LEFT);
        }

        return [
            'value' => sprintf('%04d-%02d-%02d', $year, $month, $day) . ' ' . $timeStr,
            'consumed' => $nb,
        ];
    }

    /** TIMESTAMP2：4/5/6/7 字节。返回 [value, consumed]。 */
    public static function decodeTimestamp2(string $bytes, int $offset, int $frac): array
    {
        $nb = 4 + self::fracBytes($frac);
        $b = LengthCoded::readBytesN($bytes, $offset, $nb);
        if ($b['consumed'] === 0) {
            return ['value' => '0000-00-00 00:00:00', 'consumed' => 0];
        }
        $s = $b['value'];

        $secs = LengthCoded::readUint32LE($s, 0)['value'];
        $days = (int)intdiv($secs, 86400);
        $dateStr = self::mysqlDaysToDate($days);

        $ts = (int)$secs % 86400;
        $hour = intdiv($ts, 3600);
        $minute = intdiv($ts % 3600, 60);
        $second = $ts % 60;

        $timeStr = sprintf('%02d:%02d:%02d', $hour, $minute, $second);
        if ($frac > 0) {
            $fv = self::readFrac($s, 4, $frac);
            $timeStr .= '.' . str_pad((string)$fv, $frac, '0', STR_PAD_LEFT);
        }

        return ['value' => $dateStr . ' ' . $timeStr, 'consumed' => $nb];
    }

    /** TIME2：3/4/5 字节。返回 [value, consumed]。 */
    public static function decodeTime2(string $bytes, int $offset, int $frac): array
    {
        $nb = 3 + self::fracBytes($frac);
        $b = LengthCoded::readBytesN($bytes, $offset, $nb);
        if ($b['consumed'] === 0) {
            return ['value' => '00:00:00', 'consumed' => 0];
        }
        $s = $b['value'];

        $neg = (ord($s[0]) & 0x80) !== 0;
        $days = ord($s[0]) & 0x7F;
        $hour = ord($s[1]);
        $minute = ord($s[2]);

        $totalSec = $days * 86400 + $hour * 3600 + $minute * 60;
        if ($neg) { $totalSec = -$totalSec; }
        $sign = '';
        if ($totalSec < 0) { $sign = '-'; $totalSec = -$totalSec; }
        $h = intdiv($totalSec, 3600);
        $m = intdiv($totalSec % 3600, 60);
        $sec = $totalSec % 60;

        $timeStr = $sign . sprintf('%02d:%02d:%02d', $h, $m, $sec);
        if ($frac > 0) {
            $fv = self::readFrac($s, 3, $frac);
            $timeStr .= '.' . str_pad((string)$fv, $frac, '0', STR_PAD_LEFT);
        }

        return ['value' => $timeStr, 'consumed' => $nb];
    }

    private static function datetime2Size(int $frac): int
    {
        if ($frac === 0) return 5;
        if ($frac <= 2) return 6;
        if ($frac <= 4) return 7;
        return 8;
    }

    private static function fracBytes(int $frac): int
    {
        if ($frac === 0) return 0;
        return (int)ceil($frac / 2);
    }

    /** 读取 frac 字段（1-3 字节 LE）→ 整数 */
    public static function readFrac(string $s, int $offset, int $frac): int
    {
        if ($frac <= 2) {
            return ord($s[$offset]);
        }
        if ($frac <= 4) {
            return ord($s[$offset]) | (ord($s[$offset + 1]) << 8);
        }
        return ord($s[$offset]) | (ord($s[$offset + 1]) << 8) | (ord($s[$offset + 2]) << 16);
    }
}