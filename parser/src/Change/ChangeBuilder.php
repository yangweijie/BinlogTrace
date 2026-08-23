<?php

declare(strict_types=1);

namespace Typephp\BinlogParser\Change;

use Typephp\BinlogParser\Event\TableMapCache;

/** 将解码后的行数据组装为标准化 Change 数组（供前端变更列表消费） */
final class ChangeBuilder
{
    /**
     * @param  array   $decodedRows  EventDecoder 输出的行数据
     *   write/delete: array<columnIndexStr, valueStr|null>
     *   update:       array{before: array<...>, after: array<...>}
     * @return array{changes: array[], counter: int}
     */
    public static function build(
        TableMapCache $cache,
        string $kind,
        array $decodedRows,
        int $tableId,
        int $xid,
        int $timestamp,
        string $binlogFile,
        int $binlogPos,
        int $counter,
    ): array {
        $info = $cache->get($tableId);
        if ($info === null) {
            return ['changes' => [], 'counter' => $counter];
        }

        $schema = (string)($info['schema'] ?? '');
        $table = (string)($info['table'] ?? '');
        $columns = (array)($info['columns'] ?? []);
        $type = self::mapKind($kind);
        $changes = [];

        foreach ($decodedRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $change = [
                'changeId' => 'c' . $counter,
                'schema' => $schema,
                'table' => $table,
                'type' => $type,
                'columns' => $columns,
                'oldValues' => null,
                'newValues' => null,
                'xid' => $xid,
                'timestamp' => $timestamp,
                'binlogFile' => $binlogFile,
                'binlogPos' => $binlogPos,
            ];

            if ($type === 'update') {
                $before = (array)($row['before'] ?? []);
                $after = (array)($row['after'] ?? []);
                $change['oldValues'] = count($before) > 0 ? self::rowToValues($before, $columns) : null;
                $change['newValues'] = count($after) > 0 ? self::rowToValues($after, $columns) : null;
            } elseif ($type === 'insert') {
                $change['newValues'] = self::rowToValues($row, $columns);
            } elseif ($type === 'delete') {
                $change['oldValues'] = self::rowToValues($row, $columns);
            }

            $changes[] = $change;
            $counter++;
        }

        return ['changes' => $changes, 'counter' => $counter];
    }

    private static function mapKind(string $kind): string
    {
        if ($kind === 'write_rows') { return 'insert'; }
        if ($kind === 'update_rows') { return 'update'; }
        if ($kind === 'delete_rows') { return 'delete'; }
        return 'insert';
    }

    /** 将按列索引索引的行数据映射为按列名索引的值字典 */
    private static function rowToValues(array $row, array $columns): array
    {
        $values = [];
        foreach ($columns as $i => $name) {
            $idx = (string)$i;
            if (array_key_exists($idx, $row)) {
                $values[$name] = $row[$idx];
            } else {
                $values[$name] = null;
            }
        }
        return $values;
    }
}
