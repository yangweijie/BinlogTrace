<?php

declare(strict_types=1);

namespace Typephp\BinlogParser\Rollback;

/** SQL 字面量转义与子句构造（对应 Spec 值转义规则与前端 rollback-gen.ts 算法） */
final class SqlLiteral
{
    /** 判断是否为纯数字字面量（int 或 decimal，无引号直出） */
    public static function isNumericLiteral(string $v): bool
    {
        $len = strlen($v);
        if ($len === 0) {
            return false;
        }
        $i = 0;
        if ($v[$i] === '-') {
            $i = 1;
        }
        if ($i >= $len) {
            return false;
        }
        $hasDot = false;
        for (; $i < $len; $i++) {
            $c = $v[$i];
            if ($c >= '0' && $c <= '9') {
                continue;
            }
            if ($c === '.' && !$hasDot) {
                $hasDot = true;
                continue;
            }
            return false;
        }
        return true;
    }

    /** 判断是否为 X'..' 十六进制字面量 */
    public static function isHexLiteral(string $v): bool
    {
        $len = strlen($v);
        if ($len < 4) {
            return false;
        }
        if ($v[0] !== 'X' || $v[1] !== '\'') {
            return false;
        }
        if ($v[$len - 1] !== '\'') {
            return false;
        }
        $hexStart = 2;
        $hexEnd = $len - 1;
        $hexLen = $hexEnd - $hexStart;
        if ($hexLen === 0 || ($hexLen % 2) !== 0) {
            return false;
        }
        for ($i = $hexStart; $i < $hexEnd; $i++) {
            $c = $v[$i];
            $ok = ($c >= '0' && $c <= '9')
                || ($c >= 'a' && $c <= 'f')
                || ($c >= 'A' && $c <= 'F');
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /** 单值转义：数字/16 进制原样；字符串单引号并转义；空值调用方处理为 NULL */
    public static function literal(string $v): string
    {
        if (self::isNumericLiteral($v)) {
            return $v;
        }
        if (self::isHexLiteral($v)) {
            return $v;
        }
        $escaped = str_replace(['\\', '\''], ['\\\\', '\'\''], $v);
        return '\'' . $escaped . '\'';
    }

    /** WHERE c1=... AND c2=...（全列等值，NULL 列用 IS NULL） */
    public static function whereClause(array $values, array $columns): string
    {
        $parts = [];
        foreach ($columns as $c) {
            $id = '`' . self::quoteIdentifier($c) . '`';
            if (!array_key_exists($c, $values)) {
                $parts[] = $id . ' IS NULL';
                continue;
            }
            $val = $values[$c];
            if ($val === null) {
                $parts[] = $id . ' IS NULL';
            } else {
                $parts[] = $id . '=' . self::literal((string)$val);
            }
        }
        return implode(' AND ', $parts);
    }

    /** SET c1=..., c2=... */
    public static function setClause(array $values, array $columns): string
    {
        $parts = [];
        foreach ($columns as $c) {
            if (!array_key_exists($c, $values)) {
                continue;
            }
            $id = '`' . self::quoteIdentifier($c) . '`';
            $val = $values[$c];
            if ($val === null) {
                $parts[] = $id . '=NULL';
            } else {
                $parts[] = $id . '=' . self::literal((string)$val);
            }
        }
        return implode(',', $parts);
    }

    /** 反引号包裹的列名清单 */
    public static function colList(array $columns): string
    {
        $parts = [];
        foreach ($columns as $c) {
            $parts[] = '`' . self::quoteIdentifier($c) . '`';
        }
        return implode(',', $parts);
    }

    public static function quoteIdentifier(string $s): string
    {
        return str_replace('`', '``', $s);
    }
}
