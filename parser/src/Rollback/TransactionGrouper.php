<?php

declare(strict_types=1);

namespace Typephp\BinlogParser\Rollback;

/** 按 xid 分组并按提交时刻降序排列事务（AC-09：后提交先回滚，组内正序） */
final class TransactionGrouper
{
    /**
     * @param  array[]  $changes  parse_binlog 输出的 change 数组
     * @return array[]  排序后的事务分组，每组为 change 数组
     */
    public static function group(array $changes): array
    {
        $groups = [];
        $maxTs = [];
        foreach ($changes as $c) {
            $xid = (int)($c['xid'] ?? 0);
            if (!array_key_exists($xid, $groups)) {
                $groups[$xid] = [];
                $maxTs[$xid] = 0;
            }
            $groups[$xid][] = $c;
            $ts = (int)($c['timestamp'] ?? 0);
            if ($ts > $maxTs[$xid]) {
                $maxTs[$xid] = $ts;
            }
        }

        // 按 maxTs 降序插入排序（无闭包，纯内联）
        $keys = array_keys($groups);
        $n = count($keys);
        for ($i = 1; $i < $n; $i++) {
            $key = $keys[$i];
            $val = $maxTs[$key];
            $j = $i - 1;
            while ($j >= 0 && $maxTs[$keys[$j]] < $val) {
                $keys[$j + 1] = $keys[$j];
                $j--;
            }
            $keys[$j + 1] = $key;
        }

        $result = [];
        foreach ($keys as $k) {
            $result[] = $groups[$k];
        }
        return $result;
    }
}
