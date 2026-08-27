// parser.worker.ts — 解析 Worker：优先加载 TypePHP WASM（Jco createRuntime 模式），未就绪时回退 TS 参考实现
// 参考 examples/wasm-hello/typephp-worker.mjs；WASM 产物由后端按 parser/project.yml 构建后置于 src/wasm/generated/

import { checkBinlogCfg } from '../lib/check-cfg';
import { generateRollback } from '../lib/rollback-gen';
import { demoParse } from './demo-parse';
import type { CheckMetaInput } from '../types/binlog';

const ctx = self as unknown as {
  onmessage: ((ev: MessageEvent) => void) | null;
  postMessage(msg: unknown): void;
};

interface TypephpApi {
  parseBinlog(s: string): Promise<string>;
  generateRollback(s: string): Promise<string>;
  checkBinlogCfg(s: string): Promise<string>;
}

let wasmApi: TypephpApi | null | undefined;

async function loadTypephpApi(): Promise<TypephpApi | null> {
  try {
    // 后端构建后生成（parser/project.yml -> wasm-browser-dir: generated）
    const wasmUrl = '/src/wasm/generated/program.js';
    const { instantiate } = await import(/* @vite-ignore */ wasmUrl);
    const { WASIShim } = await import('@bytecodealliance/preview2-shim/instantiation');
    const wasi = new WASIShim({ sandbox: { args: [], env: {} } });
    const component = await instantiate(null, wasi.getImportObject());
    const runtime = await component.api.createRuntime();
    return {
      parseBinlog: (s: string) => Promise.resolve(runtime.parseBinlog(s)),
      generateRollback: (s: string) => Promise.resolve(runtime.generateRollback(s)),
      checkBinlogCfg: (s: string) => Promise.resolve(runtime.checkBinlogCfg(s)),
    };
  } catch {
    return null;
  }
}

interface WorkerRequest {
  id: string;
  cmd: 'parse' | 'generate' | 'check';
  payload: {
    eventsJson?: string;
    changesJson?: string;
    metaJson?: string;
    demo?: boolean;
    independentTx?: boolean;
  };
}

async function handle(req: WorkerRequest): Promise<unknown> {
  if (wasmApi === undefined) {
    wasmApi = await loadTypephpApi();
  }
  const api = wasmApi;

  if (req.cmd === 'check') {
    if (api) {
      return JSON.parse(await api.checkBinlogCfg(String(req.payload.metaJson)));
    }
    return checkBinlogCfg(JSON.parse(String(req.payload.metaJson)) as CheckMetaInput);
  }

  if (req.cmd === 'generate') {
    const independentTx = req.payload.independentTx === true;
    if (api) {
      // WASM 路径暂不支持 independentTx（WASM 接口固定），回退到 TS 实现
      return generateRollback(JSON.parse(String(req.payload.changesJson)), independentTx);
    }
    return generateRollback(JSON.parse(String(req.payload.changesJson)), independentTx);
  }

  // parse
  if (req.payload.demo === true) {
    return demoParse(String(req.payload.eventsJson));
  }
  if (api) {
    return JSON.parse(await api.parseBinlog(String(req.payload.eventsJson)));
  }
  return {
    ok: false,
    changes: [],
    warnings: ['解析核心（WASM）未就绪：请确认后端已按 parser/project.yml 构建并放置生成模块。'],
    error: '解析核心（WASM）未就绪',
  };
}

ctx.onmessage = async (ev: MessageEvent) => {
  const req = ev.data as WorkerRequest;
  try {
    const result = await handle(req);
    ctx.postMessage({ id: req.id, ok: true, result });
  } catch (err) {
    ctx.postMessage({ id: req.id, ok: false, error: err instanceof Error ? err.message : String(err) });
  }
};
