// mock-agent.ts — 演示模式代理模拟器（协议 v2 语义对齐；无真实 MySQL 时供 UI/QA 联调）

import type {
  ConnectedPayload,
  BinlogEventPayload,
  QueryResultPayload,
} from '../types/api';
import type { WsHandlers } from './ws';

export interface DemoDumpOptions {
  binlogFile: string;
  binlogPos: number;
  slaveFlags: number;
  demoCount: number;
  startMs: number;
  endMs: number;
}

export interface DemoSimulateOptions {
  simulatePermMissing?: boolean;
  simulateFormatMixed?: boolean;
  simulateRowImageMinimal?: boolean;
  simulateNoBinlog?: boolean;
}

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

export class MockAgent {
  private handlers: WsHandlers;
  private timers: number[] = [];
  private simulate: DemoSimulateOptions = {};

  constructor(handlers: WsHandlers) {
    this.handlers = handlers;
  }

  setSimulate(opts: DemoSimulateOptions): void {
    this.simulate = opts;
  }

  async connect(_opts: { host: string; port: number; user: string; password: string; database?: string }): Promise<ConnectedPayload> {
    await delay(350);
    const privs = ['SELECT', 'REPLICATION SLAVE', 'REPLICATION CLIENT'];
    if (this.simulate.simulatePermMissing) {
      privs.splice(0, 1); // 去掉 SELECT
    }
    const format = this.simulate.simulateFormatMixed ? 'MIXED' : 'ROW';
    const rowImage = this.simulate.simulateRowImageMinimal ? 'MINIMAL' : 'FULL';
    const hasBinlog = !this.simulate.simulateNoBinlog;
    return {
      ok: true,
      serverVersion: '5.7.44-log',
      binlogFile: hasBinlog ? 'mysql-bin.000003' : null,
      binlogPos: hasBinlog ? 4 : null,
      binlogFormat: format,
      binlogRowImage: rowImage,
      hasBinlog,
      serverId: Math.floor(Math.random() * 1_000_000) + 1000,
      userPrivileges: privs,
    };
  }

  /** 流式发射演示 binlog-event（时间戳落在 [startMs, endMs] 内，最后发 binlog-end） */
  startDump(payload: DemoDumpOptions): void {
    const { demoCount, startMs, endMs } = payload;
    const count = Math.max(1, demoCount);
    const span = Math.max(1, endMs - startMs);
    const step = span / count;
    const file = payload.binlogFile;
    const emitAt = (i: number): void => {
      const timer = window.setTimeout(() => {
        const event: BinlogEventPayload = {
          raw: btoa(String((i + 1) * 7919)), // 演示标记，demo-parse 据此确定性生成变更
          eventType: 30,
          binlogFile: file,
          binlogPos: payload.binlogPos + i * 32,
          timestamp: Math.floor(startMs + i * step),
          serverId: 1,
        };
        this.handlers.onEvent(event);
      }, 60 + i * 12);
      this.timers.push(timer);
    };
    for (let i = 0; i < count; i += 1) {
      emitAt(i);
    }
    const endTimer = window.setTimeout(() => {
      this.handlers.onHeartbeat({ ts: Date.now(), binlogPos: payload.binlogPos + count * 32 });
      this.handlers.onDumpEnd();
    }, 90 + count * 12);
    this.timers.push(endTimer);
  }

  async query<T = QueryResultPayload>(_sql: string): Promise<T> {
    await delay(120);
    return { columns: [], rows: [], affectedRows: 0 } as T;
  }

  close(): void {
    this.timers.forEach((t) => window.clearTimeout(t));
    this.timers = [];
  }
}
