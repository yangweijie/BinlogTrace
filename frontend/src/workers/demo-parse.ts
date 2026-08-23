// demo-parse.ts — 演示模式解析器：确定性生成标准化变更（WASM 解析核心未就绪时用于 UI 联调）
// 输出结构对齐 04-架构细化 §2.3 ParseResult

import type { ParseResult, Change } from '../types/binlog';

interface DemoMeta {
  count?: number;
  seed?: number;
  types?: Array<'insert' | 'update' | 'delete'>;
  startMs?: number;
  endMs?: number;
}

function mulberry32(seed: number): () => number {
  let a = seed >>> 0;
  return () => {
    a += 0x6d2b79f5;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function pad(n: number): string {
  return String(n).padStart(2, '0');
}

function fmtTime(ms: number): string {
  const d = new Date(ms);
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

const DEFAULT_COLUMNS = ['id', 'status', 'pay_amount', 'updated_at', 'remark'];

export function demoParse(eventsJson: string): ParseResult {
  let parsed: { events: unknown[]; metadata: Record<string, unknown> };
  try {
    parsed = JSON.parse(eventsJson) as { events: unknown[]; metadata: Record<string, unknown> };
  } catch {
    return { ok: false, changes: [], warnings: ['演示解析失败：输入 JSON 非法'], error: '演示解析失败：输入 JSON 非法' };
  }

  const meta = (parsed.metadata?.demo ?? {}) as DemoMeta;
  const db = typeof parsed.metadata?.database === 'string' ? parsed.metadata.database : 'shop';
  const tables = (parsed.metadata?.tables ?? {}) as Record<string, unknown>;
  const table = Object.keys(tables)[0] ?? 'orders';
  const columns =
    tables[table] && Array.isArray((tables[table] as { columns?: unknown[] }).columns)
      ? ((tables[table] as { columns: unknown[] }).columns.map((c) => (c as { name?: string }).name ?? 'col'))
      : DEFAULT_COLUMNS;

  const count = meta.count ?? 1284;
  const seed = meta.seed ?? 7;
  const types = meta.types ?? ['insert', 'update', 'delete'];
  const startMs = meta.startMs ?? Date.now() - 3600_000;
  const endMs = meta.endMs ?? Date.now();
  const rand = mulberry32(seed);
  const span = Math.max(1, endMs - startMs);

  const changes: Change[] = [];
  let idCounter = 0;
  for (let i = 0; i < count; i += 1) {
    const type = types[i % types.length];
    const ts = Math.floor(startMs + (i / Math.max(1, count - 1)) * span);
    idCounter += 1;
    const rowId = String(1000 + i);
    const status = type === 'update' ? '2' : '1';
    const amount = (19.9 + rand() * 800).toFixed(2);
    const remark = `订单 #${rowId} 演示数据`;

    const base: Record<string, string | null> = {};
    columns.forEach((c, idx) => {
      if (c === 'id') base[c] = rowId;
      else if (c === 'status') base[c] = status;
      else if (c === 'pay_amount') base[c] = amount;
      else if (c === 'updated_at') base[c] = fmtTime(ts);
      else if (c === 'remark') base[c] = remark;
      else base[c] = `v${idx}-${rowId}`;
    });

    const oldValues = type === 'insert' ? null : { ...base };
    const newValues = type === 'delete' ? null : { ...base };
    if (type === 'update' && oldValues && newValues) {
      newValues.status = '2';
      newValues.pay_amount = (parseFloat(amount) + 100).toFixed(2);
      newValues.updated_at = fmtTime(ts + 30_000);
      oldValues.status = '1';
    }

    changes.push({
      changeId: `c${i + 1}`,
      schema: db,
      table,
      type,
      columns: [...columns],
      primaryKeys: ['id'],
      oldValues,
      newValues,
      xid: 1000 + i,
      timestamp: ts,
      binlogFile: 'mysql-bin.000003',
      binlogPos: 1200 + i * 32,
    });
  }

  return { ok: true, changes, warnings: [] };
}
