// TracePage.tsx — 追踪工单页 `/trace`：库/表级联 + 时间范围(≤48h) + 三态类型 + 前置检查阻断/降级放行（AC-03/04/05/13）
import { useEffect, useState } from 'react';
import { ArrowLeft, Play } from 'lucide-react';
import TopBar from '../components/TopBar';
import Card from '../components/Card';
import Select from '../components/Select';
import Input from '../components/Input';
import Button from '../components/Button';
import Checkbox, { TYPE_DOTS, TYPE_LABELS } from '../components/Checkbox';
import CheckResultPanel from '../components/CheckResultPanel';
import { useAppDispatch, useAppState } from '../context/AppContext';
import { useSchemaMeta } from '../hooks/useSchemaMeta';
import { useTraceRun } from '../hooks/useTraceRun';
import { saveTraceConfig, loadTraceConfig } from '../lib/storage';
import { navigate } from '../lib/route';
import { hoursAgo, parseLocal } from '../lib/format';
import type { TraceConfig, ChangeType } from '../types/api';

const ALL_TABLE = '全部';

/** 已耗时格式化：<60s 显示秒；>=60s 显示分(秒) */
function formatElapsed(ms: number): string {
  const total = Math.floor(ms / 1000);
  if (total < 60) return `${total} 秒`;
  const m = Math.floor(total / 60);
  const s = total % 60;
  return s > 0 ? `${m} 分 ${s} 秒` : `${m} 分钟`;
}

export default function TracePage() {
  const dispatch = useAppDispatch();
  const { connection, wsMeta, checkResult, demoMode, wsStatus } = useAppState();
  const persisted = useRefLoad();
  const [db, setDb] = useState(persisted?.db ?? '');
  const [table, setTable] = useState(persisted?.table ?? ALL_TABLE);
  const [start, setStart] = useState(persisted?.start ?? hoursAgo(1));
  const [end, setEnd] = useState(persisted?.end ?? '');
  const [types, setTypes] = useState<ChangeType[]>(persisted?.types ?? ['insert', 'update', 'delete']);
  const [rangeError, setRangeError] = useState('');
  const [typeError, setTypeError] = useState('');
  const schema = useSchemaMeta(demoMode);
  const trace = useTraceRun(demoMode);
  const [elapsedMs, setElapsedMs] = useState(0);

  // 拉取进行中：每秒刷新已耗时（用于取消前的进度展示）
  useEffect(() => {
    if (!trace.collecting) {
      return;
    }
    const start = Date.now();
    setElapsedMs(0);
    const timer = window.setInterval(() => setElapsedMs(Date.now() - start), 1000);
    return () => window.clearInterval(timer);
  }, [trace.collecting]);

  useEffect(() => {
    if (!connection) navigate('/');
  }, [connection]);

  useEffect(() => {
    if (end === '') setEnd(hoursAgo(0));
  }, [end]);

  useEffect(() => {
    if (db) void schema.loadTables(db);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const onDbChange = (value: string): void => {
    setDb(value);
    setTable(ALL_TABLE);
    if (value) void schema.loadTables(value);
  };

  const toggleType = (t: ChangeType): void => {
    setTypes((prev) => (prev.includes(t) ? prev.filter((x) => x !== t) : [...prev, t]));
  };

  const quickRange = (hours: number): void => {
    setStart(hoursAgo(hours));
    setEnd(hoursAgo(0));
  };

  /** 当前时间区间命中的快捷标签小时数：仅当 结束≈现在 且 开始≈now-小时数 时命中；手动调整则全部不高亮 */
  const activeHours = ((): number | null => {
    const s = parseLocal(start).getTime();
    const e = parseLocal(end).getTime();
    const now = Date.now();
    if (Math.abs(e - now) >= 60_000) return null; // 结束时间不再是"当前时刻"
    for (const h of [1, 6, 24]) {
      if (Math.abs(s - (now - h * 3600_000)) < 60_000) return h;
    }
    return null;
  })();

  const validateRange = (): boolean => {
    const s = parseLocal(start).getTime();
    const e = parseLocal(end).getTime();
    if (!(e > s)) {
      setRangeError('结束时间必须晚于开始时间。');
      return false;
    }
    if (e - s > 48 * 3600_000) {
      setRangeError('时间跨度不能超过 48 小时。');
      return false;
    }
    setRangeError('');
    return true;
  };

  const startTrace = async (): Promise<void> => {
    setTypeError(types.length === 0 ? '请至少勾选一种追踪类型。' : '');
    if (types.length === 0 || !validateRange() || !db) return;
    const cfg: TraceConfig = { db, table: table || ALL_TABLE, start, end, types };
    saveTraceConfig(cfg);
    dispatch({ type: 'setTraceConfig', config: cfg });
    if (!wsMeta) {
      dispatch({ type: 'setStatus', status: 'error', error: '连接元数据缺失，请返回连接页重新连接。' });
      return;
    }
    await trace.run(cfg, wsMeta);
  };

  const blocking = checkResult !== null && checkResult.errors.length > 0;

  return (
    <div>
      <TopBar
        status={demoMode ? 'demo' : wsStatus === 'connected' ? 'connected' : 'error'}
        left={
          <button type="button" className="btn btn-ghost" style={{ padding: 'var(--spacing-xs)' }} onClick={() => navigate('/')} aria-label="返回连接页">
            <ArrowLeft size={16} aria-hidden="true" />
          </button>
        }
        context={connection ? `${connection.name} (${connection.host}:${connection.port})` : undefined}
      />
      <main className="page">
        <div className="trace-wrap">
          <Card title="新建追踪工单">
            <div className="form-row">
              <Select
                label="数据库"
                value={db}
                onChange={(e) => onDbChange(e.target.value)}
                options={schema.dbOptions}
                placeholder={schema.loading ? '加载中…' : '选择数据库'}
                disabled={schema.loading || Boolean(schema.error)}
              />
              <Select
                label="数据表"
                value={table}
                onChange={(e) => setTable(e.target.value)}
                options={schema.tableOptions}
                placeholder={ALL_TABLE}
              />
            </div>
            {schema.error ? <p className="field-error">{schema.error}</p> : null}

            <div className="form-row" style={{ marginTop: 'var(--spacing-md)' }}>
              <Input label="开始时间" type="datetime-local" value={start} onChange={(e) => setStart(e.target.value)} className="num" />
              <Input label="结束时间" type="datetime-local" value={end} onChange={(e) => setEnd(e.target.value)} className="num" />
            </div>
            <div style={{ display: 'flex', gap: 'var(--spacing-xs)', marginTop: 'var(--spacing-xs)' }}>
              {[
                { label: '近1小时', hours: 1 },
                { label: '近6小时', hours: 6 },
                { label: '近24小时', hours: 24 },
              ].map((q) => (
                <button key={q.hours} type="button" className={`quick-tag ${activeHours === q.hours ? 'active' : ''}`} onClick={() => quickRange(q.hours)}>
                  {q.label}
                </button>
              ))}
            </div>
            {rangeError ? <p className="field-error" style={{ marginTop: 'var(--spacing-xs)' }}>{rangeError}</p> : null}

            <div style={{ display: 'flex', gap: 'var(--spacing-md)', marginTop: 'var(--spacing-md)', flexWrap: 'wrap' }}>
              {(['insert', 'update', 'delete'] as ChangeType[]).map((t) => (
                <Checkbox key={t} label={TYPE_LABELS[t]} dotClass={TYPE_DOTS[t]} checked={types.includes(t)} onChange={() => toggleType(t)} />
              ))}
            </div>
            {typeError ? <p className="field-error" style={{ marginTop: 'var(--spacing-xs)' }}>{typeError}</p> : null}

            {checkResult ? (
              <div style={{ marginTop: 'var(--spacing-md)' }}>
                <CheckResultPanel result={checkResult} />
              </div>
            ) : null}

            <div className="trace-submit">
              <Button block loading={trace.collecting} disabled={blocking || schema.loading || trace.collecting} onClick={() => void startTrace()}>
                {trace.collecting ? null : (
                  <>
                    <Play size={16} aria-hidden="true" />
                    {blocking ? '前置检查未通过' : '开始追踪'}
                  </>
                )}
              </Button>
              {trace.collecting ? (
                <div className="trace-pull">
                  <div className="trace-pull-meta">
                    <span>
                      已拉取 <b className="num">{trace.pulledCount}</b> 条变更
                    </span>
                    <span>
                      预估 <b className="num">{trace.progress}%</b>
                    </span>
                    <span className="trace-pull-elapsed">已耗时 {formatElapsed(elapsedMs)}</span>
                    <Button variant="ghost" className="trace-cancel" onClick={() => trace.cancel()}>
                      取消拉取
                    </Button>
                  </div>
                  <div className="trace-progress" role="progressbar" aria-valuenow={trace.progress} aria-valuemin={0} aria-valuemax={100}>
                    <div className="trace-progress-bar" style={{ width: `${trace.progress}%` }} />
                  </div>
                </div>
              ) : null}
            </div>
          </Card>
        </div>
      </main>
    </div>
  );
}

function useRefLoad() {
  const [state] = useState(() => loadTraceConfig());
  return state;
}
