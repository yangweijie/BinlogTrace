// storage.ts — localStorage 封装（saved_connections / last_trace_config）

import type { SavedConnection, TraceConfig } from '../types/api';

const KEY_CONNS = 'saved_connections';
const KEY_TRACE = 'last_trace_config';

export function newId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  return `c-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

function read<T>(key: string, fallback: T): T {
  try {
    const raw = localStorage.getItem(key);
    return raw === null ? fallback : (JSON.parse(raw) as T);
  } catch {
    return fallback;
  }
}

function write(key: string, value: unknown): void {
  localStorage.setItem(key, JSON.stringify(value));
}

export function loadConnections(): SavedConnection[] {
  return read<SavedConnection[]>(KEY_CONNS, []);
}

export function upsertConnection(conn: SavedConnection): SavedConnection[] {
  const list = loadConnections();
  const idx = list.findIndex((c) => c.id === conn.id);
  if (idx === -1) {
    list.unshift(conn);
  } else {
    list[idx] = conn;
  }
  write(KEY_CONNS, list);
  return list;
}

export function removeConnection(id: string): SavedConnection[] {
  const list = loadConnections().filter((c) => c.id !== id);
  write(KEY_CONNS, list);
  return list;
}

/** 追踪配置按「连接」区分存储：切到另一台 MySQL 实例（不同 host:port）时
 *  不会串用上一个实例残留的库名/表名（例如本地 test 库 → RDS 无此库）。 */
function traceKey(host: string, port: string | number): string {
  return `${KEY_TRACE}:${host}:${port}`;
}

export function loadTraceConfig(conn?: { host: string; port: number } | null): TraceConfig | null {
  if (conn && conn.host) {
    return read<TraceConfig | null>(traceKey(conn.host, conn.port), null);
  }
  return read<TraceConfig | null>(KEY_TRACE, null);
}

export function saveTraceConfig(conn: { host: string; port: number } | null, config: TraceConfig): void {
  if (conn && conn.host) {
    write(traceKey(conn.host, conn.port), config);
  } else {
    write(KEY_TRACE, config);
  }
}
