// session.ts — 追踪会话：统一真实 WsClient 与演示 MockAgent，持有事件缓冲
import { WsClient, type WsHandlers } from './ws';
import { MockAgent, type DemoSimulateOptions } from './mock-agent';
import type { ConnectedPayload, BinlogEventPayload, BinlogChangePayload, ErrorPayload } from '../types/api';

export interface StartDumpOptions {
  binlogFile: string;
  binlogPos: number;
  slaveFlags?: number;
  demoCount?: number;
  startMs?: number;
  endMs?: number;
}

export interface TraceAgent {
  connect(opts: { host: string; port: number; user: string; password: string; database?: string }): Promise<ConnectedPayload>;
  startDump(payload: StartDumpOptions): void;
  query<T = unknown>(sql: string, database?: string): Promise<T>;
  close(): void;
}

export class TraceSession {
  agent: TraceAgent;
  private buffer: BinlogEventPayload[] = [];
  /** agent 侧 krowinski 解析后的结构化变更（真实模式，绕过 WASM parse-binlog） */
  private changes: BinlogChangePayload[] = [];
  private ended = false;
  private lastError: ErrorPayload | null = null;
  private listeners = new Set<() => void>();

  constructor(demo: boolean, simulate?: DemoSimulateOptions) {
    const handlers: WsHandlers = {
      onEvent: (p) => {
        this.buffer.push(p);
        this.emit();
      },
      onChange: (p) => {
        this.changes.push(p);
        this.emit();
      },
      onHeartbeat: () => {
        this.emit();
      },
      onError: (p) => {
        this.lastError = p;
        this.ended = true;
        this.emit();
      },
      onDumpEnd: () => {
        this.ended = true;
        this.emit();
      },
      onClose: () => {
        this.ended = true;
        this.emit();
      },
    };
    this.agent = demo ? new MockAgent(handlers) : new WsClient(handlers);
    if (demo && this.agent instanceof MockAgent) {
      this.agent.setSimulate(simulate ?? {});
    }
  }

  get events(): BinlogEventPayload[] {
    return this.buffer;
  }

  get structuredChanges(): BinlogChangePayload[] {
    return this.changes;
  }

  get isEnded(): boolean {
    return this.ended;
  }

  get error(): ErrorPayload | null {
    return this.lastError;
  }

  async query<T = unknown>(sql: string, database?: string): Promise<T> {
    return this.agent.query<T>(sql, database);
  }

  subscribe(fn: () => void): () => void {
    this.listeners.add(fn);
    return () => {
      this.listeners.delete(fn);
    };
  }

  startDump(payload: StartDumpOptions): void {
    this.buffer = [];
    this.changes = [];
    this.ended = false;
    this.lastError = null;
    this.agent.startDump(payload);
  }

  close(): void {
    this.agent.close();
  }

  private emit(): void {
    this.listeners.forEach((fn) => fn());
  }
}

let current: TraceSession | null = null;

export function createSession(demo: boolean, simulate?: DemoSimulateOptions): TraceSession {
  current = new TraceSession(demo, simulate);
  return current;
}

export function getSession(): TraceSession | null {
  return current;
}

export function clearSession(): void {
  if (current) {
    current.close();
  }
  current = null;
}
