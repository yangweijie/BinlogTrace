// ws.ts — WS 代理客户端（协议 v2：统一帧 + 请求/响应关联 + 心跳 15s/45s + server-id）
// 参考 04-架构细化 §1

import type {
  WsFrame,
  ConnectedPayload,
  BinlogEventPayload,
  BinlogChangePayload,
  HeartbeatPayload,
  ErrorPayload,
} from '../types/api';

export const WS_URL = 'ws://127.0.0.1:8080';
const CONNECT_TIMEOUT_MS = 10_000;
const IDLE_TIMEOUT_MS = 45_000;

export interface WsHandlers {
  onEvent: (p: BinlogEventPayload) => void;
  onChange: (p: BinlogChangePayload) => void;
  onHeartbeat: (p: HeartbeatPayload) => void;
  onError: (p: ErrorPayload) => void;
  onDumpEnd: () => void;
  onClose: () => void;
}

interface Pending {
  resolve: (f: WsFrame) => void;
  reject: (e: Error) => void;
  timer: number;
}

export class WsClient {
  private ws: WebSocket | null = null;
  private seq = 0;
  private pending = new Map<string, Pending>();
  private idleTimer: number | null = null;
  private closed = false;
  private handlers: WsHandlers;

  constructor(handlers: WsHandlers) {
    this.handlers = handlers;
  }

  get isOpen(): boolean {
    return this.ws !== null && this.ws.readyState === WebSocket.OPEN;
  }

  private nextId(): string {
    this.seq += 1;
    return `m-${Date.now().toString(36)}-${this.seq}`;
  }

  private sendFrame<T>(type: string, payload: T, id: string): void {
    const frame: WsFrame<T> = { v: 2, id, type, ts: Date.now(), payload };
    this.ws?.send(JSON.stringify(frame));
  }

  /** 连接并等待 connected / error 应答 */
  connect(opts: { host: string; port: number; user: string; password: string; database?: string; serverId?: number }): Promise<ConnectedPayload> {
    this.closed = false;
    return new Promise((resolve, reject) => {
      let settled = false;
      const ws = new WebSocket(WS_URL);
      this.ws = ws;

      ws.onopen = () => {
        const id = this.nextId();
        this.pending.set(id, {
          resolve: (f) => {
            if (settled) return;
            settled = true;
            if (f.type === 'error') {
              reject(new Error(`连接失败：${(f.payload as ErrorPayload).message}`));
            } else {
              resolve(f.payload as ConnectedPayload);
            }
          },
          reject: (e) => {
            if (settled) return;
            settled = true;
            reject(e);
          },
          timer: window.setTimeout(() => {
            this.pending.delete(id);
            if (!settled) {
              settled = true;
              reject(new Error('连接超时：代理未在限定时间内应答'));
            }
          }, CONNECT_TIMEOUT_MS),
        });
        this.sendFrame(
          'connect',
          {
            host: opts.host,
            port: opts.port,
            user: opts.user,
            password: opts.password,
            database: opts.database,
            serverId: opts.serverId,
            connectTimeoutMs: CONNECT_TIMEOUT_MS,
          },
          id,
        );
      };

      ws.onmessage = (ev) => this.handleMessage(String(ev.data));

      ws.onerror = () => {
        if (!settled) {
          settled = true;
          reject(new Error('无法连接 WS 代理（ws://127.0.0.1:8080）。请确认已双击运行 agent 单文件，且端口未被占用。'));
        }
      };

      ws.onclose = () => {
        if (this.idleTimer !== null) {
          window.clearTimeout(this.idleTimer);
          this.idleTimer = null;
        }
        this.handlers.onClose();
      };
    });
  }

  /** 启动 binlog dump（流式；前端通过事件/心跳/空闲判定结束；startMs/endMs 为 epoch 毫秒窗口，0/缺省 = 不限） */
  startDump(payload: {
    binlogFile: string;
    binlogPos: number;
    slaveFlags?: number;
    startMs?: number;
    endMs?: number;
  }): void {
    this.sendFrame(
      'binlog-dump',
      {
        binlogFile: payload.binlogFile,
        binlogPos: payload.binlogPos,
        slaveFlags: payload.slaveFlags ?? 0,
        startMs: payload.startMs ?? 0,
        endMs: payload.endMs ?? 0,
      },
      this.nextId(),
    );
    this.armIdleTimer();
  }

  /** 发送只读查询（INFORMATION_SCHEMA 补元数据） */
  query<T = unknown>(sql: string, database?: string): Promise<T> {
    return new Promise((resolve, reject) => {
      const id = this.nextId();
      this.pending.set(id, {
        resolve: (f) => {
          if (f.type === 'error') {
            reject(new Error((f.payload as ErrorPayload).message));
          } else {
            resolve(f.payload as T);
          }
        },
        reject,
        timer: window.setTimeout(() => {
          this.pending.delete(id);
          reject(new Error('query 超时'));
        }, 10_000),
      });
      this.sendFrame('query', { sql, database }, id);
    });
  }

  /** 主动释放（关闭 dump 线程） */
  close(): void {
    this.closed = true;
    if (this.ws) {
      try {
        this.sendFrame('close', { reason: 'user-stop' }, this.nextId());
      } catch {
        /* 忽略已关闭连接 */
      }
      this.ws.close();
    }
  }

  private armIdleTimer(): void {
    if (this.idleTimer !== null) {
      window.clearTimeout(this.idleTimer);
    }
    this.idleTimer = window.setTimeout(() => {
      // 45s 无任何帧 → 判定断线
      if (!this.closed) {
        this.handlers.onError({ code: 1007, message: '连接空闲超时（45s 无数据帧），已判定断线。' });
        this.handlers.onClose();
      }
    }, IDLE_TIMEOUT_MS);
  }

  private handleMessage(raw: string): void {
    if (this.idleTimer !== null) {
      window.clearTimeout(this.idleTimer);
      this.idleTimer = null;
    }
    let frame: WsFrame;
    try {
      frame = JSON.parse(raw) as WsFrame;
    } catch {
      this.handlers.onError({ code: 1010, message: '收到非 JSON 帧，协议错误。' });
      return;
    }

    // 请求-响应关联
    if (frame.id && this.pending.has(frame.id)) {
      const p = this.pending.get(frame.id)!;
      this.pending.delete(frame.id);
      window.clearTimeout(p.timer);
      if (frame.type === 'error') {
        p.reject(new Error((frame.payload as ErrorPayload).message));
      } else {
        p.resolve(frame);
      }
      return;
    }

    switch (frame.type) {
      case 'binlog-event':
        this.armIdleTimer();
        this.handlers.onEvent(frame.payload as BinlogEventPayload);
        break;
      case 'binlog-change':
        this.armIdleTimer();
        this.handlers.onChange(frame.payload as BinlogChangePayload);
        break;
      case 'heartbeat':
        this.armIdleTimer();
        this.handlers.onHeartbeat(frame.payload as HeartbeatPayload);
        break;
      case 'error':
        this.handlers.onError(frame.payload as ErrorPayload);
        break;
      case 'binlog-end':
        this.handlers.onDumpEnd();
        break;
      default:
        break;
    }
  }
}
