import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';

// #9：超长 ids URL 参数应回退为「筛选条件下全部变更」，并提示用户
const mockChanges = Array.from({ length: 10 }, (_, i) => ({
  changeId: `c${i}`,
  schema: 'shop',
  table: 'orders',
  type: 'update' as const,
  columns: ['id', 'status'],
  primaryKeys: ['id'],
  oldValues: { id: String(i), status: 'A' },
  newValues: { id: String(i), status: 'B' },
  xid: 1,
  timestamp: 1_700_000_000,
  binlogFile: 'mysql-bin.000001',
  binlogPos: 4,
}));

vi.mock('../context/AppContext', () => ({
  useAppState: () => ({
    changes: mockChanges,
    wsMeta: { user: 'u', password: 'p' },
    demoMode: true,
  }),
}));

vi.mock('../lib/parser-client', () => ({
  generateRollbackScript: vi.fn(async () => ({
    ok: true,
    sql: 'UPDATE `shop`.`orders` SET `status`=\'A\' WHERE `id`=1;',
    stats: { statements: 1, transactions: 1 },
    error: undefined,
  })),
}));

import RollbackPage from '../pages/RollbackPage';

describe('RollbackPage (#9 超长 ids 回退)', () => {
  beforeEach(() => {
    window.localStorage.clear();
    // 模拟超长 ids 参数（远超 1500 字符阈值）
    const longIds = Array.from({ length: 400 }, (_, i) => `c${i}`).join(',');
    window.location.hash = `#/trace/rollback?ids=${longIds}`;
  });

  it('超长 ids 时不依赖 URL，回退为当前筛选全部变更并提示', async () => {
    render(<RollbackPage />);

    // 应出现「勾选数量过多」的提示（#9 修复）
    expect(await screen.findByText(/勾选数量过多/)).toBeInTheDocument();

    // 仍应基于全部变更生成回滚 SQL（不丢数据）
    expect(await screen.findByText(/SQL 预览/)).toBeInTheDocument();
    expect(screen.getByText(/UPDATE/)).toBeInTheDocument();
  });

  it('正常长度 ids 走精确选中路径', async () => {
    window.location.hash = '#/trace/rollback?ids=c0,c1,c2';
    render(<RollbackPage />);
    // 精确 3 条选中，正常生成回滚 SQL（不触发超长回退）
    expect(await screen.findByText(/SQL 预览/)).toBeInTheDocument();
    expect(screen.getByText(/UPDATE/)).toBeInTheDocument();
  });
});
