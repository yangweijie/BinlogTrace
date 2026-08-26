// parser-client.ts — Worker 调用封装（Promise + 请求关联；解析在 Worker，主线程不阻塞 AC-11）

import type { ParseResult, RollbackResult } from '../types/binlog';
import type { CheckResult } from '../types/api';

type Cmd = 'parse' | 'generate' | 'check';

interface WorkerRequest {
  id: string;
  cmd: Cmd;
  payload: Record<string, unknown>;
}

interface WorkerResponse {
  id: string;
  ok: boolean;
  result?: unknown;
  error?: string;
}

let worker: Worker | null = null;
let seq = 0;
const pending = new Map<string, { resolve: (v: unknown) => void; reject: (e: Error) => void }>();

function getWorker(): Worker {
  if (worker === null) {
    worker = new Worker(new URL('../workers/parser.worker.ts', import.meta.url), { type: 'module' });
    worker.onmessage = (ev: MessageEvent<WorkerResponse>) => {
      const p = pending.get(ev.data.id);
      if (!p) return;
      pending.delete(ev.data.id);
      if (ev.data.ok) {
        p.resolve(ev.data.result);
      } else {
        p.reject(new Error(ev.data.error ?? '解析 Worker 异常'));
      }
    };
  }
  return worker;
}

function call<T>(cmd: Cmd, payload: Record<string, unknown>): Promise<T> {
  const id = `w-${++seq}`;
  return new Promise<T>((resolve, reject) => {
    pending.set(id, { resolve: (v) => resolve(v as T), reject });
    getWorker().postMessage({ id, cmd, payload } satisfies WorkerRequest);
  });
}

export function parseEvents(eventsJson: string, demo: boolean): Promise<ParseResult> {
  return call<ParseResult>('parse', { eventsJson, demo });
}

export function generateRollbackScript(changesJson: string, independentTx = false): Promise<RollbackResult> {
  return call<RollbackResult>('generate', { changesJson, independentTx });
}

export function checkConfig(metaJson: string): Promise<CheckResult> {
  return call<CheckResult>('check', { metaJson });
}
