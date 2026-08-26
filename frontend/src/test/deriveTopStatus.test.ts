import { describe, it, expect } from 'vitest';
import { deriveTopStatus, type AppState } from '../context/AppContext';

function base(over: Partial<AppState> = {}): AppState {
  return {
    wsStatus: 'idle',
    wsError: null,
    connection: null,
    wsMeta: null,
    checkResult: null,
    traceConfig: null,
    changes: null,
    parseStatus: 'idle',
    parseError: null,
    rollback: null,
    demoMode: false,
    agentUrl: 'http://127.0.0.1:8080',
    agentReachable: null,
    ...over,
  };
}

describe('deriveTopStatus', () => {
  it('demo 模式优先显示演示模式', () => {
    expect(deriveTopStatus(base({ demoMode: true, agentReachable: true }))).toBe('demo');
  });

  it('WS 已连接优先显示已连接', () => {
    expect(deriveTopStatus(base({ wsStatus: 'connected', agentReachable: false }))).toBe('connected');
  });

  it('有 wsMeta 视为已连接', () => {
    expect(deriveTopStatus(base({ wsMeta: {} as never, agentReachable: null }))).toBe('connected');
  });

  it('首次进入首页 ping 成功 → 显示代理已连接', () => {
    expect(deriveTopStatus(base({ wsStatus: 'idle', agentReachable: true }))).toBe('connected');
  });

  it('ping 失败 → 显示代理异常', () => {
    expect(deriveTopStatus(base({ wsStatus: 'idle', agentReachable: false }))).toBe('error');
  });

  it('wsStatus 为 error 且未 ping → 显示异常', () => {
    expect(deriveTopStatus(base({ wsStatus: 'error', agentReachable: null }))).toBe('error');
  });

  it('未连接且 ping 未确认 → 空闲', () => {
    expect(deriveTopStatus(base({ wsStatus: 'idle', agentReachable: null }))).toBe('idle');
  });
});
