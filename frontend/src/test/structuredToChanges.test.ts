import { describe, it, expect } from 'vitest';
import { structuredToChanges } from '../hooks/useTraceRun';
import type { BinlogChangePayload } from '../types/api';

function mk(ts: number, over: Partial<BinlogChangePayload> = {}): BinlogChangePayload {
  return {
    schema: 'shop',
    table: 'orders',
    kind: 'update',
    columns: ['id', 'status'],
    primaryKeys: ['id'],
    before: { id: 1, status: 'A' },
    after: { id: 1, status: 'B' },
    xid: 1,
    timestamp: ts,
    binlogFile: 'mysql-bin.000001',
    binlogPos: 4,
    ...over,
  };
}

describe('structuredToChanges (#5 窗口过滤单遍遍历)', () => {
  it('不做窗口过滤时全部映射', () => {
    const list = [mk(1_700_000_000), mk(1_700_000_100)];
    const out = structuredToChanges(list);
    expect(out).toHaveLength(2);
    expect(out[0].schema).toBe('shop');
    expect(out[0].type).toBe('update');
  });

  it('按毫秒窗口过滤（真实模式 timestamp 为秒，需归一化 ×1000）', () => {
    const list = [
      mk(1_700_000_000), // 秒 → 1_700_000_000_000
      mk(1_700_000_050), // 秒 → 1_700_000_050_000
      mk(1_700_000_200), // 秒 → 1_700_000_200_000（超出窗口）
    ];
    const out = structuredToChanges(list, [1_700_000_000_000, 1_700_000_100_000]);
    expect(out).toHaveLength(2);
  });

  it('毫秒级 timestamp 直接比较，不二次乘 1000', () => {
    const list = [mk(1_700_000_000_000), mk(1_700_000_200_000)];
    const out = structuredToChanges(list, [1_700_000_000_000, 1_700_000_100_000]);
    expect(out).toHaveLength(1);
  });

  it('窗口边界 [start,end] 闭区间', () => {
    const list = [mk(1_700_000_000_000), mk(1_700_000_100_000)];
    const out = structuredToChanges(list, [1_700_000_000_000, 1_700_000_100_000]);
    expect(out).toHaveLength(2);
  });

  it('空列表返回空数组', () => {
    expect(structuredToChanges([], [0, 1])).toEqual([]);
  });
});
