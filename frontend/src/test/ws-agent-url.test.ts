import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { WsClient, type WsHandlers } from '../lib/ws';
import type { ConnectedPayload } from '../types/api';

const noopHandlers: WsHandlers = {};

const okConnected: ConnectedPayload & { session?: string } = {
  ok: true,
  serverVersion: '8.0.0',
  binlogFile: 'mysql-bin.000001',
  binlogPos: 4,
  binlogFormat: 'ROW',
  binlogRowImage: 'FULL',
  hasBinlog: true,
  serverId: 1,
  userPrivileges: ['SELECT', 'REPLICATION SLAVE', 'REPLICATION CLIENT'],
  session: 's1',
};

const connectOpts = {
  host: '127.0.0.1',
  port: 3306, // MySQL 端口，绝不应出现在代理地址中
  user: 'root',
  password: '',
  database: 'shop',
};

describe('WsClient 代理地址不被 MySQL 端口污染 (#TP-AOT-021 修复)', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn());
  });
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it('显式 agentUrl 时，/connect 请求打到代理端口(8080) 而非 MySQL 端口(3306)', async () => {
    const captured: string[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string | URL | Request) => {
        captured.push(String(url));
        return {
          ok: true,
          status: 200,
          json: async () => ({ v: 2, id: 'x', type: 'connected', ts: Date.now(), payload: okConnected }),
        } as Response;
      }),
    );
    const client = new WsClient(noopHandlers, { agentUrl: 'http://127.0.0.1:8080' });
    await client.connect(connectOpts);
    expect(captured).toHaveLength(1);
    expect(captured[0]).toBe('http://127.0.0.1:8080/connect');
    expect(captured[0]).not.toContain(':3306');
  });

  it('未传 agentUrl 时回退默认 8080，MySQL 端口不污染 base', async () => {
    const captured: string[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string | URL | Request) => {
        captured.push(String(url));
        return {
          ok: true,
          status: 200,
          json: async () => ({ v: 2, id: 'x', type: 'connected', ts: Date.now(), payload: okConnected }),
        } as Response;
      }),
    );
    const client = new WsClient(noopHandlers); // 无 agentUrl
    await client.connect(connectOpts);
    expect(captured[0]).toBe('http://127.0.0.1:8080/connect');
    expect(captured[0]).not.toContain(':3306');
  });

  it('agentUrl 为空串时回退默认 8080，而非用 MySQL 地址拼接', async () => {
    const captured: string[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string | URL | Request) => {
        captured.push(String(url));
        return {
          ok: true,
          status: 200,
          json: async () => ({ v: 2, id: 'x', type: 'connected', ts: Date.now(), payload: okConnected }),
        } as Response;
      }),
    );
    const client = new WsClient(noopHandlers, { agentUrl: '   ' });
    await client.connect({ ...connectOpts, host: 'db.internal', port: 3306 });
    expect(captured[0]).toBe('http://127.0.0.1:8080/connect');
    expect(captured[0]).not.toContain('db.internal');
    expect(captured[0]).not.toContain(':3306');
  });

  it('自定义代理地址(非默认端口)被原样使用', async () => {
    const captured: string[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string | URL | Request) => {
        captured.push(String(url));
        return {
          ok: true,
          status: 200,
          json: async () => ({ v: 2, id: 'x', type: 'connected', ts: Date.now(), payload: okConnected }),
        } as Response;
      }),
    );
    const client = new WsClient(noopHandlers, { agentUrl: 'http://proxy.example.com:9000' });
    await client.connect(connectOpts);
    expect(captured[0]).toBe('http://proxy.example.com:9000/connect');
  });
});
