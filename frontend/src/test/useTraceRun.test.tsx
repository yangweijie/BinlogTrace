import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import { AppProvider } from '../context/AppContext';
import { createSession } from '../lib/session';
import type { ConnectedPayload, TraceConfig } from '../types/api';
import type { Change } from '../types/binlog';

// #4：cancel 应真正停止采集并清理结果缓存（不残留脏数据）
// #3：采集过程中增量维护 pulledCount（增量路径被执行，而非全量遍历）

const meta = { user: 'u', password: 'p', host: '127.0.0.1', port: 8080 } as unknown as ConnectedPayload;

const cfg: TraceConfig = {
  db: 'shop',
  table: ['全部'],
  types: ['insert', 'update', 'delete'],
  start: '2024-01-01 00:00:00',
  end: '2024-01-01 01:00:00',
};

vi.mock('../lib/change-cache', () => ({
  clearChanges: vi.fn(),
  saveChanges: vi.fn(),
  loadChanges: vi.fn((): Change[] => []),
}));

import { useTraceRun } from '../hooks/useTraceRun';
import { clearChanges } from '../lib/change-cache';

function wrapper({ children }: { children: React.ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

describe('useTraceRun (#4 取消清理 / #3 增量进度)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.localStorage.clear();
    window.location.hash = '';
    createSession(true);
  });

  it('demo 模式 run 后 collecting 为 true，且增量 pulledCount 随流推进', async () => {
    const { result } = renderHook(() => useTraceRun(true), { wrapper });

    await act(async () => {
      await result.current.run(cfg, meta);
    });

    expect(result.current.collecting).toBe(true);

    // mock-agent 持续发射，增量回调应使 pulledCount 增长（#3 路径执行）
    await waitFor(() => expect(result.current.pulledCount).toBeGreaterThan(0), { timeout: 4000 });
  });

  it('cancel 停止采集并清理结果缓存', async () => {
    const { result } = renderHook(() => useTraceRun(true), { wrapper });

    await act(async () => {
      await result.current.run(cfg, meta);
    });
    expect(result.current.collecting).toBe(true);

    // 在流结束前取消
    act(() => {
      result.current.cancel();
    });

    await waitFor(() => expect(result.current.collecting).toBe(false));
    expect(window.location.hash).not.toContain('#/trace/result');
    // run 与 cancel 各调用一次 clearChanges，确保脏结果被清理（#4）
    expect(clearChanges).toHaveBeenCalled();
  });
});
