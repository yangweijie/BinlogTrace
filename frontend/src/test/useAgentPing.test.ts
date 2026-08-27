import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useAppState } from '../context/AppContext';
import type { AppState } from '../context/AppContext';

// 验证：页面挂载时自动 ping 一次代理，不可达时 dispatch setAgentReachable(false) → wsStatus=error
const dispatchSpy = vi.fn();

vi.mock('../context/AppContext', () => ({
  useAppState: vi.fn(() => ({ agentUrl: 'http://127.0.0.1:8080' })),
  useAppDispatch: () => dispatchSpy,
}));

vi.mock('../components/AgentConfig', () => ({
  getStoredAgentUrl: () => 'http://127.0.0.1:8080',
  pingAgent: vi.fn(),
}));

import { useAgentPing } from '../hooks/useAgentPing';
import { pingAgent } from '../components/AgentConfig';

describe('useAgentPing — 挂载自动 ping 并同步状态', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(useAppState).mockReturnValue({ agentUrl: 'http://127.0.0.1:8080' } as unknown as AppState);
  });
  afterEach(() => {
    vi.resetAllMocks();
  });

  it('挂载时发起一次 ping（使用当前 agentUrl）', async () => {
    vi.mocked(pingAgent).mockResolvedValue(true);
    renderHook(() => useAgentPing());
    await new Promise((r) => setTimeout(r, 10));
    expect(pingAgent).toHaveBeenCalledTimes(1);
    expect(pingAgent).toHaveBeenCalledWith('http://127.0.0.1:8080');
  });

  it('ping 不可达时 dispatch setAgentReachable(false)', async () => {
    vi.mocked(pingAgent).mockResolvedValue(false);
    renderHook(() => useAgentPing());
    await new Promise((r) => setTimeout(r, 10));
    expect(dispatchSpy).toHaveBeenCalledWith({ type: 'setAgentReachable', ok: false });
  });

  it('ping 可达时 dispatch setAgentReachable(true)', async () => {
    vi.mocked(pingAgent).mockResolvedValue(true);
    renderHook(() => useAgentPing());
    await new Promise((r) => setTimeout(r, 10));
    expect(dispatchSpy).toHaveBeenCalledWith({ type: 'setAgentReachable', ok: true });
  });

  it('agentUrl 变化时重新 ping 一次', async () => {
    vi.mocked(pingAgent).mockResolvedValue(true);
    const { rerender } = renderHook(() => useAgentPing());
    await new Promise((r) => setTimeout(r, 10));
    expect(pingAgent).toHaveBeenCalledTimes(1);

    // 改变 agentUrl 后重新渲染，应触发新的 ping
    vi.mocked(useAppState).mockReturnValue({ agentUrl: 'http://other:9000' } as unknown as AppState);
    rerender();
    await new Promise((r) => setTimeout(r, 10));
    expect(pingAgent).toHaveBeenCalledWith('http://other:9000');
  });
});
