<?php

declare(strict_types=1);

namespace Typephp\BinlogParser\Rollback;

/** flashback 回滚 SQL 生成（AC-06/07/08/09/10）
 *
 * 三规则：
 *   INSERT → DELETE FROM t WHERE <全列=newValues>
 *   UPDATE → UPDATE t SET <oldValues> WHERE <newValues>
 *   DELETE → INSERT INTO t (cols) VALUES (oldValues)
 *
 * 事务按 xid 提交时刻降序，组内正序，事务包裹 START TRANSACTION / COMMIT。
 */
final class RollbackGenerator
{
    public static function generate(array $changes): array
    {
        $n = count($changes);
        if ($n === 0) {
            return [
                'ok' => false,
                'sql' => '',
                'stats' => ['statements' => 0, 'transactions' => 0],
                'error' => '未生成回滚脚本：当前没有选中的变更。请返回变更列表页勾选需要回滚的记录。',
            ];
        }

        $groups = TransactionGrouper::group($changes);
        $now = date('Y-m-d H:i:s');
        $blocks = ['-- 回滚脚本 binlog-parser v1.0；生成时间 ' . $now];
        $statements = 0;

        foreach ($groups as $group) {
            $first = $group[0];
            $xid = (int)($first['xid'] ?? 0);
            $lines = ['START TRANSACTION;'];

            foreach ($group as $c) {
                $type = (string)($c['type'] ?? '');
                $schema = (string)($c['schema'] ?? '');
                $table = (string)($c['table'] ?? '');
                $changeId = (string)($c['changeId'] ?? '');
                $binlogFile = (string)($c['binlogFile'] ?? '');
                $binlogPos = (int)($c['binlogPos'] ?? 0);
                $columns = (array)($c['columns'] ?? []);
                $oldValues = $c['oldValues'] ?? null;
                $newValues = $c['newValues'] ?? null;

                $t = '`' . SqlLiteral::quoteIdentifier($schema) . '`.`' . SqlLiteral::quoteIdentifier($table) . '`';
                $stmt = self::buildStatement($type, $t, $columns, $oldValues, $newValues);

                $lines[] = '-- changeId=' . $changeId . ' ' . strtoupper($type) . ' ' . $schema . '.' . $table . ' @ ' . $binlogFile . ':' . $binlogPos . ' ; xid=' . $xid;
                $lines[] = $stmt;
                $statements++;
            }

            $lines[] = 'COMMIT;';
            $blocks[] = implode("\n", $lines);
        }

        return [
            'ok' => true,
            'sql' => implode("\n\n", $blocks),
            'stats' => ['statements' => $statements, 'transactions' => count($groups)],
        ];
    }

    private static function buildStatement(string $type, string $t, array $columns, ?array $oldValues, ?array $newValues): string
    {
        if ($type === 'insert' && $newValues !== null) {
            return 'DELETE FROM ' . $t . "\nWHERE " . SqlLiteral::whereClause($newValues, $columns) . ';';
        }
        if ($type === 'update' && $oldValues !== null && $newValues !== null) {
            return 'UPDATE ' . $t . ' SET ' . SqlLiteral::setClause($oldValues, $columns)
                . "\nWHERE " . SqlLiteral::whereClause($newValues, $columns) . ';';
        }
        if ($type === 'delete' && $oldValues !== null) {
            return 'INSERT INTO ' . $t . ' (' . SqlLiteral::colList($columns) . ')'
                . "\nVALUES (" . self::formatValues($columns, $oldValues) . ');';
        }
        return '-- 缺少回滚所需的 before/after 镜像';
    }

    private static function formatValues(array $columns, ?array $values): string
    {
        $parts = [];
        foreach ($columns as $col) {
            if ($values === null) {
                $parts[] = 'NULL';
            } elseif (!array_key_exists($col, $values)) {
                $parts[] = 'NULL';
            } elseif ($values[$col] === null) {
                $parts[] = 'NULL';
            } else {
                $parts[] = SqlLiteral::literal((string)$values[$col]);
            }
        }
        return implode(',', $parts);
    }
}
