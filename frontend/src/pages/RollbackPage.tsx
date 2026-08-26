// RollbackPage.tsx — 回滚脚本页 `/rollback`：SQL 预览 + 复制/下载 + 事务注释块 + 执行提示卡（AC-06/07/08/09/10）
import { useEffect, useMemo, useState } from 'react';
import { ArrowLeft, Copy, Download, TriangleAlert, FileWarning } from 'lucide-react';
import TopBar from '../components/TopBar';
import Button from '../components/Button';
import EmptyState from '../components/EmptyState';
import SqlViewer from '../components/SqlViewer';
import { useAppState } from '../context/AppContext';
import { loadChanges } from '../lib/change-cache';
import { generateRollbackScript } from '../lib/parser-client';
import { useRoute, navigate } from '../lib/route';
import { toast } from '../lib/toast';
import { splitSqlLines } from '../lib/sql-highlight';
import { formatCount, formatLocalInput } from '../lib/format';
import type { RollbackResult } from '../types/binlog';

/** 把 'YYYY-MM-DDTHH:mm' / 'YYYY-MM-DD HH:mm' 压缩成 'YYYYMMDDHHmm' 用于文件名 */
function compactTime(dt: string): string {
  return formatLocalInput(dt).replace(/[^0-9]/g, '').slice(0, 12);
}

/**
 * 生成带上下文的回滚文件名，格式：
 *   rollback_{库}_{表或all}_{变更类型}_{时间范围起-止}_{生成时间}.sql
 * 例如：rollback_blog_all_insert_20260826T0930-20260826T1030_20260826T1045.sql
 */
function buildRollbackFileName(opts: {
  db: string;
  tables: string[];
  types: string;
  start: string;
  end: string;
}): string {
  const { db, tables, types, start, end } = opts;
  const tablePart = tables.length === 0 ? 'all' : tables.length === 1 ? tables[0] : `tbl${tables.length}`;
  const typePart = types && types !== 'all' ? types : 'all';
  const range = `${compactTime(start) || 'na'}-${compactTime(end) || 'na'}`;
  const now = new Date();
  const pad = (n: number): string => String(n).padStart(2, '0');
  const stamp = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
  return `rollback_${db}_${tablePart}_${typePart}_${range}_${stamp}.sql`;
}

function downloadSql(sql: string, name: string): void {
  const blob = new Blob([sql], { type: 'text/sql;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

async function copySql(sql: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(sql);
  } catch {
    const ta = document.createElement('textarea');
    ta.value = sql;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }
}

export default function RollbackPage() {
  const { changes: ctxChanges, wsMeta, demoMode } = useAppState();
  const route = useRoute();
  const [cached] = useState(() => loadChanges());
  const [rollback, setRollback] = useState<RollbackResult | null>(null);
  const [status, setStatus] = useState<'loading' | 'done' | 'error'>('loading');
  const [error, setError] = useState('');
  const changeId = route.params.get('changeId') ?? '';
  const idsParam = route.params.get('ids') ?? '';

  const allChanges = useMemo(() => (ctxChanges && ctxChanges.length > 0 ? ctxChanges : cached ?? []), [ctxChanges, cached]);
  // #9：URL 上的 ids 拼接在 query string 中，巨量勾选会超长（浏览器/服务器 ~2-8KB 上限，极端场景 414）。
  // 超过阈值时回退为"选中当前过滤条件下的全部变更"（与未勾选语义一致），避免截断丢数据。
  const IDS_URL_LIMIT = 1500;
  const idsTooLong = idsParam.length > IDS_URL_LIMIT;
  const selected = useMemo(() => {
    if (idsTooLong) return allChanges;
    if (changeId) return allChanges.filter((c) => c.changeId === changeId);
    if (idsParam) {
      const ids = idsParam.split(',').filter(Boolean);
      // 按 URL 顺序选中并过滤到仍存在的变更
      const byId = new Map(allChanges.map((c) => [c.changeId, c]));
      return ids.filter((id) => byId.has(id)).map((id) => byId.get(id)!);
    }
    return allChanges;
  }, [allChanges, changeId, idsParam, idsTooLong]);

  useEffect(() => {
    if (idsTooLong) {
      toast('勾选数量过多，已自动回退为当前筛选条件下的全部变更生成回滚脚本。');
    }
  }, [idsTooLong]);

  useEffect(() => {
    let cancelled = false;
    if (selected.length === 0) {
      setStatus('error');
      setError('未生成回滚脚本：当前没有选中的变更。请返回变更列表页勾选需要回滚的记录。');
      return;
    }
    setStatus('loading');
    generateRollbackScript(JSON.stringify(selected))
      .then((res) => {
        if (cancelled) return;
        if (res.ok) {
          setRollback(res);
          setStatus('done');
        } else {
          setStatus('error');
          setError(res.error ?? '回滚脚本生成失败：变更数据缺失。请返回变更列表重新选择。');
        }
      })
      .catch((err: Error) => {
        if (!cancelled) {
          setStatus('error');
          setError(err.message);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [selected]);

  const lineCount = rollback ? splitSqlLines(rollback.sql).length : 0;
  const db = route.params.get('db') ?? selected[0]?.schema ?? '';
  const typesParam = route.params.get('types') ?? 'all';
  const startParam = route.params.get('start') ?? '';
  const endParam = route.params.get('end') ?? '';

  // 涉及到的表（去重，用于文件名）；全部=空，单表=表名，多表=数量标记
  const involvedTables = useMemo(() => {
    const set = new Set<string>();
    selected.forEach((c) => set.add(c.table));
    return [...set];
  }, [selected]);

  const onCopy = async (): Promise<void> => {
    if (!rollback) return;
    await copySql(rollback.sql);
    toast(`已复制 ${formatCount(lineCount)} 行 SQL 到剪贴板`);
  };

  const onDownload = (): void => {
    if (!rollback) return;
    const name = buildRollbackFileName({
      db,
      tables: involvedTables,
      types: typesParam,
      start: startParam,
      end: endParam,
    });
    downloadSql(rollback.sql, name);
  };

  return (
    <div>
      <TopBar
        status={demoMode ? 'demo' : wsMeta ? 'connected' : 'idle'}
        left={
          <button type="button" className="btn btn-ghost" style={{ padding: 'var(--spacing-xs)' }} onClick={() => navigate('/trace/result')} aria-label="返回变更列表">
            <ArrowLeft size={16} aria-hidden="true" />
          </button>
        }
        context={`回滚脚本 · ${selected.length > 0 ? `${formatCount(selected.length)} 条变更` : '—'}`}
      />
      <main className="page">
        <div className="rollback-wrap">
          {status === 'error' ? (
            <div className="card">
              <EmptyState icon={FileWarning} title="回滚脚本生成失败" body={error} />
            </div>
          ) : status === 'loading' ? (
            <div className="card">
              {Array.from({ length: 6 }, (_, i) => (
                <div key={i} className="skeleton" style={{ height: 18, marginBottom: 'var(--spacing-sm)' }} />
              ))}
            </div>
          ) : rollback ? (
            <>
              <div className="rollback-toolbar">
                <span className="text-secondary">
                  {rollback.stats.transactions} 个事务 · {formatCount(rollback.stats.statements)} 条语句 ·{' '}
                  <span className="num">{formatCount(lineCount)}</span> 行
                </span>
                <div className="rollback-toolbar-actions">
                  <Button onClick={() => void onCopy()}>
                    <Copy size={20} aria-hidden="true" />
                    复制全部
                  </Button>
                  <Button variant="secondary" onClick={onDownload}>
                    <Download size={20} aria-hidden="true" />
                    下载 .sql
                  </Button>
                </div>
              </div>

              <div className="sql-viewport">
                <div className="sql-toolbar">
                  <span>SQL 预览（{formatCount(lineCount)} 行）</span>
                  {demoMode ? <span className="text-secondary">演示数据</span> : null}
                </div>
                <SqlViewer sql={rollback.sql} />
              </div>

              <div className="notice notice-warn execution-hint">
                <TriangleAlert size={16} aria-hidden="true" />
                <span>
                  本工具不自动执行回滚。请在 MySQL 客户端人工执行；执行前建议先开启事务并备份数据，确认无误后 COMMIT。
                </span>
              </div>
            </>
          ) : null}
        </div>
      </main>
    </div>
  );
}
