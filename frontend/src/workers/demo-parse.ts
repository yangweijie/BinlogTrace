// demo-parse.ts — 演示模式解析器：确定性生成标准化变更（WASM 解析核心未就绪时用于 UI 联调）
// 输出结构对齐 04-架构细化 §2.3 ParseResult

import type { ParseResult, Change } from '../types/binlog';
import { DEMO_TABLES, DEMO_TABLE_COLUMNS } from '../lib/demo-data';

interface DemoMeta {
  count?: number;
  seed?: number;
  types?: Array<'insert' | 'update' | 'delete'>;
  startMs?: number;
  endMs?: number;
  table?: string;
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

/** 按表返回列结构；未知表回退到默认订单式列 */
function columnsForTable(table: string): string[] {
  return DEMO_TABLE_COLUMNS[table] ?? DEFAULT_COLUMNS;
}

export function demoParse(eventsJson: string): ParseResult {
  let parsed: { events: unknown[]; metadata: Record<string, unknown> };
  try {
    parsed = JSON.parse(eventsJson) as { events: unknown[]; metadata: Record<string, unknown> };
  } catch {
    return { ok: false, changes: [], warnings: ['演示解析失败：输入 JSON 非法'], error: '演示解析失败：输入 JSON 非法' };
  }

  const meta = (parsed.metadata?.demo ?? {}) as DemoMeta;
  const count = meta.count ?? 1284;
  const seed = meta.seed ?? 7;
  const rand = mulberry32(seed);
  const types = meta.types ?? ['insert', 'update', 'delete'];
  const startMs = meta.startMs ?? Date.now() - 3600_000;
  const endMs = meta.endMs ?? Date.now();
  const span = Math.max(1, endMs - startMs);

  const db = typeof parsed.metadata?.database === 'string' ? parsed.metadata.database : 'shop';
  const realDemoTables = DEMO_TABLES[db] ?? ['orders', 'users'];
  const targetTable = typeof meta.table === 'string' ? meta.table : '';
  function pickTable(i: number): string {
    if (targetTable && targetTable !== '全部') return targetTable;
    // 全部：按索引散列到该库真实表，保证多表分布均匀
    return realDemoTables[i % realDemoTables.length];
  }
  const columns = DEFAULT_COLUMNS;

  const changes: Change[] = [];
  let idCounter = 0;
  for (let i = 0; i < count; i += 1) {
    const table = pickTable(i);
    const columns = columnsForTable(table);
    const type = types[i % types.length];
    const ts = Math.floor(startMs + (i / Math.max(1, count - 1)) * span);
    idCounter += 1;
    const rowId = String(1000 + i);
    const status = type === 'update' ? '2' : '1';
    const amount = (19.9 + rand() * 800).toFixed(2);
    const remark = `演示 #${rowId}`;

    const base: Record<string, string | null> = {};
    columns.forEach((c, idx) => {
      if (c === 'id') base[c] = rowId;
      else if (c === 'status') base[c] = status;
      else if (['pay_amount', 'amount', 'salary'].includes(c)) base[c] = amount;
      else if (c === 'updated_at' || c === 'created_at' || c === 'paid_at' || c === 'hire_date') base[c] = fmtTime(ts);
      else if (c === 'published' || c === 'refunded') base[c] = type === 'delete' ? '0' : '1';
      else if (c === 'remark') base[c] = remark;
      else base[c] = `v${idx}-${rowId}`;
    });

    const oldValues = type === 'insert' ? null : { ...base };
    const newValues = type === 'delete' ? null : { ...base };
    if (type === 'update' && oldValues && newValues) {
      if (columns.includes('status')) {
        newValues.status = '2';
        oldValues.status = '1';
      }
      if (columns.includes('pay_amount')) {
        newValues.pay_amount = (parseFloat(amount) + 100).toFixed(2);
      }
      if (columns.includes('amount')) {
        newValues.amount = (parseFloat(amount) + 100).toFixed(2);
      }
      if (columns.includes('salary')) {
        newValues.salary = String(Math.round((parseFloat(amount) + 1000)));
      }
      if (columns.includes('updated_at')) {
        newValues.updated_at = fmtTime(ts + 30_000);
      }
    }

    changes.push({
      changeId: `c${i + 1}`,
      schema: db,
      table: table,
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
