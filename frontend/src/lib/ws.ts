// ws.ts — HTTP 模式代理客户端（协议 v2，替代原 WebSocket 客户端）
// 为后续 TypePHP 改为 HTTP 版做准备：前端不再依赖 WebSocket，统一走 HTTP。
//   POST /connect  请求-响应：返回 connected（含 session）或 error
//   POST /dump     SSE 流式：持续推送 binlog-change / heartbeat / error / binlog-end
//   POST /query    请求-响应：返回 query-result 或 error
//   POST /close    销毁会话
// 对外仍实现原 TraceAgent 契约（connect/startDump/query/close + WsHandlers），session.ts 与 UI 无需改动。

import type {
  ConnectedPayload,
  BinlogEventPayload,
  BinlogChangePayload,
  ErrorPayload,
  HeartbeatPayload,
} from '../types/api';
import type { StartDumpOptions } from './session';

const BASE = 'http://127.0.0.1:8080';

function rid(): string {
  return 'c' + Math.random().toString(36).slice(2, 10);
}

export interface WsHandlers {
  onEvent?: (p: BinlogEventPayload) => void;
  onChange?: (p: BinlogChangePayload) => void;
  onHeartbeat?: (p: HeartbeatPayload) => void;
  onError?: (p: ErrorPayload) => void;
  onDumpEnd?: () => void;
  onClose?: () => void;
}

interface Frame {
  v: number;
  id: string;
  type: string;
  ts: number;
  payload: unknown;
}

export class WsClient {
  private handlers: WsHandlers;
  private session = '';

  constructor(handlers: WsHandlers) {
    this.handlers = handlers;
  }

  /** 建立连接：POST /connect，读取 JSON 响应，返回 connected 载荷（含 session token） */
  async connect(opts: {
    host: string;
    port: number;
    user: string;
    password: string;
    database?: string;
  }): Promise<ConnectedPayload> {
    const frame: Frame = {
      v: 2,
      id: rid(),
      type: 'connect',
      ts: Date.now(),
      payload: opts,
    };
    let resp: Response;
    try {
      resp = await fetch(`${BASE}/connect`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(frame),
      });
    } catch (err) {
      throw new Error(`连接代理失败：${err instanceof Error ? err.message : String(err)}`);
    }
    if (!resp.ok) {
      throw new Error(`连接代理失败：HTTP ${resp.status}`);
    }
    const data = (await resp.json()) as Frame;
    if (data.type === 'error') {
      const p = (data.payload ?? {}) as ErrorPayload;
      throw new Error(p.message || '连接失败');
    }
    const payload = (data.payload ?? {}) as ConnectedPayload & { session?: string };
    this.session = payload.session ?? '';
    return payload as ConnectedPayload;
  }

  /** 启动 binlog 追踪：POST /dump，以 SSE 流消费变更帧 */
  startDump(payload: StartDumpOptions): void {
    const frame: Frame = {
      v: 2,
      id: rid(),
      type: 'binlog-dump',
      ts: Date.now(),
      payload: { ...payload, session: this.session },
    };
    void this.streamPost(`${BASE}/dump`, frame);
  }

  /** 只读查询：POST /query，读取 JSON 响应 */
  async query<T = unknown>(sql: string, database?: string): Promise<T> {
    const frame: Frame = {
      v: 2,
      id: rid(),
      type: 'query',
      ts: Date.now(),
      payload: { sql, database, session: this.session },
    };
    let resp: Response;
    try {
      resp = await fetch(`${BASE}/query`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(frame),
      });
    } catch (err) {
      throw new Error(`查询失败：${err instanceof Error ? err.message : String(err)}`);
    }
    if (!resp.ok) {
      throw new Error(`查询失败：HTTP ${resp.status}`);
    }
    const data = (await resp.json()) as Frame;
    if (data.type === 'error') {
      const p = (data.payload ?? {}) as ErrorPayload;
      throw new Error(p.message || '查询失败');
    }
    return (data.payload ?? {}) as T;
  }

  /** 关闭会话：POST /close（不阻塞） */
  close(): void {
    const frame: Frame = {
      v: 2,
      id: rid(),
      type: 'close',
      ts: Date.now(),
      payload: { session: this.session },
    };
    void fetch(`${BASE}/close`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(frame),
    }).catch(() => {});
  }

  /** 读取 SSE 流，逐事件解析并分发到 handlers */
  private async streamPost(url: string, frame: Frame): Promise<void> {
    let resp: Response;
    try {
      resp = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(frame),
      });
    } catch (err) {
      this.handlers.onError?.({ code: 1006, message: `追踪请求失败：${err instanceof Error ? err.message : String(err)}` });
      return;
    }
    if (!resp.ok || !resp.body) {
      this.handlers.onError?.({ code: 1006, message: `追踪请求失败：HTTP ${resp.status}` });
      return;
    }
    const reader = resp.body.getReader();
    const decoder = new TextDecoder();
    let buf = '';
    try {
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buf += decoder.decode(value, { stream: true });
        let sep: number;
        while ((sep = buf.indexOf('\n\n')) !== -1) {
          const chunk = buf.slice(0, sep);
          buf = buf.slice(sep + 2);
          const dataLine = chunk
            .split('\n')
            .find((line) => line.startsWith('data:'));
          if (!dataLine) continue;
          const json = dataLine.slice(5).trim();
          if (!json) continue;
          let obj: Frame;
          try {
            obj = JSON.parse(json) as Frame;
          } catch {
            continue;
          }
          this.dispatch(obj);
        }
      }
    } catch (err) {
      this.handlers.onError?.({ code: 1006, message: `追踪流中断：${err instanceof Error ? err.message : String(err)}` });
    }
    // 流结束：通知结束（binlog-end 通常已由服务端显式发送，此处兜底）
    this.handlers.onClose?.();
  }

  private dispatch(frame: Frame): void {
    const p = (frame.payload ?? {}) as
      | BinlogChangePayload
      | BinlogEventPayload
      | HeartbeatPayload
      | ErrorPayload;
    switch (frame.type) {
      case 'dump-started':
        break;
      case 'binlog-change':
        this.handlers.onChange?.(p as BinlogChangePayload);
        break;
      case 'binlog-event':
        this.handlers.onEvent?.(p as BinlogEventPayload);
        break;
      case 'heartbeat':
        this.handlers.onHeartbeat?.(p as HeartbeatPayload);
        break;
      case 'binlog-end':
        this.handlers.onDumpEnd?.();
        break;
      case 'error':
        this.handlers.onError?.(p as ErrorPayload);
        break;
      default:
        break;
    }
  }
}
