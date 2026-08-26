// useTraceRun.ts — 追踪采集运行器：前置检查 → binlog-dump 采集 → 解析 → 跳变更列表
import { useEffect, useRef, useState } from 'react';
import { useAppDispatch } from '../context/AppContext';
import { getSession } from '../lib/session';
import { checkConfig, parseEvents } from '../lib/parser-client';
import { toCheckMeta } from '../lib/check-meta';
import { saveChanges, clearChanges } from '../lib/change-cache';
import { navigate, buildQuery } from '../lib/route';
import { parseLocal } from '../lib/format';
import type { TraceConfig, ConnectedPayload, BinlogChangePayload } from '../types/api';
import type { Change } from '../types/binlog';

export const DEMO_COUNT_PER_HOUR = 100;

function calcDemoCount(startMs: number, endMs: number): number {
  const hours = Math.max(0, endMs - startMs) / 3600_000;
  return Math.max(1, Math.round(DEMO_COUNT_PER_HOUR * hours));
}

function eventTypeName(t: number): string {
  switch (t) {
    case 19:
      return 'table_map';
    case 30:
      return 'write_rows';
    case 31:
      return 'update_rows';
    case 32:
      return 'delete_rows';
    case 16:
      return 'xid';
    default:
      return 'other';
  }
}

interface CollectedEvent {
  type: string;
  rawBase64: string;
  binlogFile: string;
  binlogPos: number;
  timestamp: number;
  serverId: number;
}

/** agent 侧 krowinski 结构化变更 → 标准 Change（真实模式，绕过 WASM parse-binlog）
 * 可选 winMs=[startMs,endMs]：单次遍历内同时完成窗口过滤与映射，避免 filter+map 两遍 O(n)（#5 优化） */
export function structuredToChanges(list: BinlogChangePayload[], winMs?: [number, number]): Change[] {
  const out: Change[] = [];
  let i = 0;
  for (const c of list) {
    if (winMs) {
      const ms = c.timestamp > 1e11 ? c.timestamp : c.timestamp * 1000;
      if (ms < winMs[0] || ms > winMs[1]) continue;
    }
    out.push({
      changeId: `c${i}`,
      schema: c.schema,
      table: c.table,
      type: c.kind,
      columns: c.columns,
      primaryKeys: c.primaryKeys ?? [],
      oldValues: c.before ? stringifyValues(c.before) : null,
      newValues: c.after ? stringifyValues(c.after) : null,
      xid: c.xid,
      timestamp: c.timestamp,
      binlogFile: c.binlogFile,
      binlogPos: c.binlogPos,
    });
    i += 1;
  }
  return out;
}

/** krowinski 值可能为 number（如 id:8），Change 契约要求 string|null */
function stringifyValues(v: Record<string, string | number | null>): Record<string, string | null> {
  const out: Record<string, string | null> = {};
  for (const [k, val] of Object.entries(v)) {
    out[k] = val === null ? null : String(val);
  }
  return out;
}

function buildQueryString(cfg: TraceConfig): string {
  // table 为数组：含"全部"或空时不传（后端返回所有表，前端按选中表过滤）
  const hasAll = cfg.table.includes('全部') || cfg.table.length === 0;
  return buildQuery({
    db: cfg.db,
    table: hasAll ? '' : cfg.table.join(','),
    start: cfg.start,
    end: cfg.end,
    types: cfg.types.join(','),
  });
}

async function finalize(
  events: CollectedEvent[],
  cfg: TraceConfig,
  demoMode: boolean,
  dispatch: ReturnType<typeof useAppDispatch>,
  demoCount: number,
): Promise<void> {
  dispatch({ type: 'setParse', status: 'parsing' });
  const startMs = parseLocal(cfg.start).getTime();
  const endMs = parseLocal(cfg.end).getTime();
  const eventsJson = JSON.stringify({
    events,
    metadata: {
      database: cfg.db,
      tables: Object.fromEntries(cfg.table.filter((t) => t !== '全部').map((t) => [`${cfg.db}.${t}`, { columns: [] }])),
      demo: { count: demoCount, seed: 7, types: cfg.types, startMs, endMs, table: cfg.table },
    },
  });
  try {
    const result = await parseEvents(eventsJson, demoMode);
    dispatch({ type: 'setParse', status: 'done', changes: result.changes, error: result.error ?? null });
    saveChanges(result.changes);
    navigate(`/trace/result${buildQueryString(cfg)}`);
  } catch (err) {
    dispatch({ type: 'setParse', status: 'error', error: err instanceof Error ? err.message : String(err) });
  }
}

/** 真实模式：agent 已解析好结构化变更，按窗口过滤后落库跳转（不再调 WASM parse-binlog） */
function finalizeStructured(
  changes: BinlogChangePayload[],
  cfg: TraceConfig,
  dispatch: ReturnType<typeof useAppDispatch>,
): void {
  dispatch({ type: 'setParse', status: 'parsing' });
  const startMs = parseLocal(cfg.start).getTime();
  const endMs = parseLocal(cfg.end).getTime();
  // 窗口过滤与映射合并为单遍遍历（#5 优化）
  const mapped = structuredToChanges(changes, [startMs, endMs]);
  dispatch({ type: 'setParse', status: 'done', changes: mapped, error: null });
  saveChanges(mapped);
  navigate(`/trace/result${buildQueryString(cfg)}`);
}

export function useTraceRun(demoMode: boolean) {
  const dispatch = useAppDispatch();
  const [collecting, setCollecting] = useState(false);
  const [progress, setProgress] = useState(0);
  const [pulledCount, setPulledCount] = useState(0);
  const cfgRef = useRef<TraceConfig | null>(null);
  const cancelledRef = useRef(false);
  const unsubRef = useRef<(() => void) | null>(null);
  const demoCountRef = useRef<number>(DEMO_COUNT_PER_HOUR);
  // #3 优化：增量维护计数/最新时间戳，避免每次回调全量扫描（高频 binlog 流下 O(n)→O(增量)）
  const latestMsRef = useRef(0);
  const dmlCountRef = useRef(0);
  const prevEventsLenRef = useRef(0);
  const prevChangesLenRef = useRef(0);

  const run = async (cfg: TraceConfig, meta: ConnectedPayload): Promise<void> => {
    cfgRef.current = cfg;
    cancelledRef.current = false;
    latestMsRef.current = 0;
    dmlCountRef.current = 0;
    prevEventsLenRef.current = 0;
    prevChangesLenRef.current = 0;
    clearChanges();
    // 演示模式无需真实 MySQL 前置检查（无代理），直接跳过阻断
    if (demoMode) {
      dispatch({ type: 'setCheck', result: { ok: true, errors: [], warnings: [] } });
    } else {
      const result = await checkConfig(toCheckMeta(meta));
      dispatch({ type: 'setCheck', result });
      if (result.errors.length > 0) {
        return; // AC-03 阻断
      }
    }
    const session = getSession();
    if (!session) {
      dispatch({ type: 'setStatus', status: 'error', error: '连接会话缺失，请返回连接页重新连接。' });
      return;
    }
    const startMs = parseLocal(cfg.start).getTime();
    const endMs = parseLocal(cfg.end).getTime();
    const demoCount = demoMode ? calcDemoCount(startMs, endMs) : 0;
    demoCountRef.current = demoMode ? demoCount : DEMO_COUNT_PER_HOUR;
    session.startDump({
      binlogFile: meta.binlogFile ?? 'mysql-bin.000001',
      binlogPos: meta.binlogPos ?? 4,
      slaveFlags: 0,
      demoCount,
      startMs,
      endMs,
    });
    setCollecting(true);
    setProgress(0);
    setPulledCount(0);
  };

  /** 取消拉取：停止采集并清理订阅（不跳转结果页） */
  const cancel = (): void => {
    cancelledRef.current = true;
    const session = getSession();
    if (session) {
      session.close();
    }
    unsubRef.current?.();
    unsubRef.current = null;
    // 取消的追踪不应残留结果缓存，避免后续 ResultPage 误读到脏数据（#4 清理）
    try {
      clearChanges();
    } catch {
      // 忽略清理异常
    }
    setCollecting(false);
    setProgress(0);
    setPulledCount(0);
  };

  useEffect(() => {
    if (!collecting) return;
    const session = getSession();
    if (!session) return;
    let done = false;
    const unsub = session.subscribe(() => {
      if (done || cancelledRef.current) return;
      const events = session.events;
      const changes = session.structuredChanges;
      const cfg = cfgRef.current;
      if (!cfg) return;
      const startMs = parseLocal(cfg.start).getTime();
      const endMs = parseLocal(cfg.end).getTime();
      // agent 事件 timestamp 为 epoch 秒，endMs 为毫秒；demo 事件（mock-agent）为毫秒。
      // 以 1e11（1973 年）为阈值归一化到毫秒后再比较，否则真实模式 passedEnd 永不成立
      const toMs = (t: number): number => (t > 1e11 ? t : t * 1000);
      let passedEnd = false;
      if (demoMode) {
        // 仅处理本次新增事件（增量），避免每次回调全量扫描
        const slice = events.slice(prevEventsLenRef.current);
        prevEventsLenRef.current = events.length;
        let dml = 0;
        for (const e of slice) {
          if (e.eventType === 30 || e.eventType === 31 || e.eventType === 32) dml++;
          if (toMs(e.timestamp) > endMs) passedEnd = true;
        }
        dmlCountRef.current += dml;
        setPulledCount(dmlCountRef.current);
        setProgress(Math.min(100, Math.round((events.length / demoCountRef.current) * 100)));
      } else {
        // 仅处理本次新增变更（增量），维护最新时间戳用于进度估算
        const slice = changes.slice(prevChangesLenRef.current);
        prevChangesLenRef.current = changes.length;
        for (const c of slice) {
          const ms = toMs(c.timestamp);
          if (ms > latestMsRef.current) latestMsRef.current = ms;
          if (ms > endMs) passedEnd = true;
        }
        setPulledCount(changes.length);
        const span = endMs - startMs;
        if (latestMsRef.current > 0 && span > 0) {
          setProgress(Math.max(0, Math.min(99, Math.round(((latestMsRef.current - startMs) / span) * 100))));
        }
      }
      if (session.isEnded || passedEnd) {
        done = true;
        unsub();
        setCollecting(false);
        if (demoMode) {
          const keep = ['table_map', 'write_rows', 'update_rows', 'delete_rows', 'xid'];
          const mapped: CollectedEvent[] = [];
          for (const e of events) {
            const t = eventTypeName(e.eventType);
            if (!keep.includes(t)) continue;
            mapped.push({
              type: t,
              rawBase64: e.raw,
              binlogFile: e.binlogFile,
              binlogPos: e.binlogPos,
              timestamp: e.timestamp,
              serverId: e.serverId,
            });
          }
          void finalize(mapped, cfg, demoMode, dispatch, demoCountRef.current);
        } else {
          finalizeStructured(changes, cfg, dispatch);
        }
      }
    });
    unsubRef.current = unsub;
    return () => {
      unsub();
      // #2/#4 修复：组件卸载且仍在采集时，真正关闭底层 SSE 流，避免流泄漏
      if (!done) {
        const s = getSession();
        s?.close();
      }
      if (unsubRef.current === unsub) unsubRef.current = null;
    };
  }, [collecting, demoMode]);

  // P1-5 修复：HTTP 模式下进度条同步需要额外轮询机制（当前依赖 WebSocket 帧接收更新状态，HTTP 模式需定时轮询 fetch 获取最新状态）
  return { collecting, progress, pulledCount, run, cancel };
}
