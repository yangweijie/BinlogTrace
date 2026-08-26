// ResultPage.tsx — 变更列表页 `/trace/result`：三态色条表格 + 类型/表/列筛选 + 分页 100/页 + 右下浮动生成回滚（AC-05/11）
import { useEffect, useMemo, useState } from 'react';
import { ArrowLeft, SearchX, WandSparkles } from 'lucide-react';
import TopBar from '../components/TopBar';
import Button from '../components/Button';
import MultiSelect from '../components/MultiSelect';
import TypePill from '../components/TypePill';
import EmptyState from '../components/EmptyState';
import ChangeDetailModal from '../components/ChangeDetailModal';
import { useAppDispatch, useAppState, deriveTopStatus } from '../context/AppContext';
import { useAgentPing } from '../hooks/useAgentPing';
import { loadChanges } from '../lib/change-cache';
import { useRoute, navigate, buildQuery } from '../lib/route';
import { formatTime, formatCount, epochMs, formatLocalInput } from '../lib/format';
import type { Change } from '../types/binlog';

const PAGE_SIZE = 100;
const TYPE_OPTIONS: Array<{ value: string; label: string }> = [
  { value: 'all', label: '全部' },
  { value: 'insert', label: 'INSERT' },
  { value: 'update', label: 'UPDATE' },
  { value: 'delete', label: 'DELETE' },
];

function collectColumns(changes: Change[]): string[] {
  const set = new Set<string>();
  changes.forEach((c) => c.columns.forEach((col) => set.add(col)));
  return [...set].sort();
}

interface ColumnOptionGroup {
  label: string;
  options: string[];
}

function collectColumnGroups(changes: Change[]): ColumnOptionGroup[] {
  const byTable = new Map<string, Set<string>>();
  changes.forEach((c) => {
    const set = byTable.get(c.table) ?? new Set();
    c.columns.forEach((col) => set.add(col));
    byTable.set(c.table, set);
  });
  return [...byTable.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([table, cols]) => ({
      label: table,
      options: [...cols].sort().map((col) => `${table}.${col}`),
    }));
}

/** 列筛选匹配：同表内字段为"且"，不同表之间为"或" */
function matchColumnFilter(c: Change, filter: string[]): boolean {
  if (filter.length === 0) return true;
  const changed = changedColumns(c);
  const byTable = new Map<string, string[]>();
  const bare: string[] = [];
  for (const f of filter) {
    const dot = f.indexOf('.');
    if (dot === -1) {
      bare.push(f);
      continue;
    }
    const t = f.slice(0, dot);
    const col = f.slice(dot + 1);
    const arr = byTable.get(t) ?? [];
    arr.push(col);
    byTable.set(t, arr);
  }
  // 裸列名：任意表包含所有勾选列即匹配
  if (bare.length > 0 && bare.every((col) => changed.includes(col))) return true;
  // 按表分组：组内"且"，组间"或"
  for (const [t, cols] of byTable) {
    if (c.table === t && cols.every((col) => changed.includes(col))) return true;
  }
  return false;
}

/** 该变更**实际发生变化的列**：update=before/after 不同，insert=newValues 列，delete=oldValues 列 */
function changedColumns(c: Change): string[] {
  if (c.type === 'update' && c.oldValues && c.newValues) {
    const keys = new Set([...Object.keys(c.oldValues), ...Object.keys(c.newValues)]);
    return [...keys].filter((k) => c.oldValues![k] !== c.newValues![k]);
  }
  const src = c.type === 'delete' ? c.oldValues : c.newValues;
  return src ? Object.keys(src) : c.columns;
}

function primaryKey(change: Change): string {
  const idCol = change.columns.find((c) => c.toLowerCase() === 'id');
  return idCol ?? change.columns[0] ?? '';
}

export default function ResultPage() {
  const state = useAppState();
  const { changes: ctxChanges, parseStatus, parseError, demoMode, wsMeta, agentUrl } = state;
  const dispatch = useAppDispatch();
  useAgentPing();
  const route = useRoute();
  const [cached] = useState<Change[] | null>(() => loadChanges());
  const [typeFilter, setTypeFilter] = useState<string>('all');
  const [tableFilter, setTableFilter] = useState<string[]>(['全部']);
  const [columnFilter, setColumnFilter] = useState<string[]>([]);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState(-1);
  const [detail, setDetail] = useState<Change | null>(null);
  const [checkedIds, setCheckedIds] = useState<Set<string>>(new Set());

  const changes = useMemo<Change[]>(() => {
    if (ctxChanges && ctxChanges.length > 0) return ctxChanges;
    return cached ?? [];
  }, [ctxChanges, cached]);

  const allTables = useMemo(() => {
    const dataTables = [...new Set(changes.map((c) => c.table ?? '').filter((t) => t !== '' && t !== '全部'))];
    return ['全部', ...dataTables];
  }, [changes]);
  const tablesSet = useMemo(() => new Set(changes.map((c) => c.table)), [changes]);
  const isMultiTable = tablesSet.size > 1;
  const visibleChanges = useMemo(() => {
    if (tableFilter.includes('全部') || tableFilter.length === 0) return changes;
    const set = new Set(tableFilter);
    return changes.filter((c) => set.has(c.table));
  }, [changes, tableFilter]);
  const showColumnGroups = (tableFilter.includes('全部') || tableFilter.length > 1) && isMultiTable;
  const columnOptions = useMemo(
    () => (showColumnGroups ? [] : collectColumns(visibleChanges)),
    [visibleChanges, showColumnGroups],
  );
  const columnGroups = useMemo(
    () => (showColumnGroups ? collectColumnGroups(visibleChanges) : []),
    [visibleChanges, showColumnGroups],
  );

  const filtered = useMemo(
    () =>
      changes.filter((c) => {
        if (typeFilter !== 'all' && c.type !== typeFilter) return false;
        if (!tableFilter.includes('全部') && tableFilter.length > 0 && !tableFilter.includes(c.table)) return false;
        return matchColumnFilter(c, columnFilter);
      }),
    [changes, typeFilter, tableFilter, columnFilter],
  );

  const pageCount = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const safePage = Math.min(page, pageCount);
  const pageRows = filtered.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  useEffect(() => {
    setPage(1);
    setSelected(-1);
  }, [typeFilter, tableFilter, columnFilter]);

  // 切换表筛选时清空列筛选：多表/单表的列选项格式不同，避免状态不一致
  useEffect(() => {
    setColumnFilter([]);
  }, [tableFilter]);

  const goto = (p: number): void => {
    setPage(p);
    setSelected(-1);
  };

  /** 当前页全选/全不选；勾选集合始终落在 filtered 范围内（跨页保留） */
  const togglePageRows = (): void => {
    const pageIds = pageRows.map((c) => c.changeId);
    const allChecked = pageIds.length > 0 && pageIds.every((id) => checkedIds.has(id));
    setCheckedIds((prev) => {
      const next = new Set(prev);
      pageIds.forEach((id) => (allChecked ? next.delete(id) : next.add(id)));
      return next;
    });
  };

  const toggleRow = (id: string): void => {
    setCheckedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  // 生成回滚：优先勾选集合；未勾选时兜底为"全部筛选结果"
  const rollbackTarget = checkedIds.size > 0 ? filtered.filter((c) => checkedIds.has(c.changeId)) : filtered;

  const onKeyDown = (e: React.KeyboardEvent): void => {
    if (!pageRows.length) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setSelected((s) => Math.min(s + 1, pageRows.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setSelected((s) => Math.max(s - 1, 0));
    } else if (e.key === 'Enter' && selected >= 0) {
      setDetail(pageRows[selected]);
    }
  };

  const traceDb = route.params.get('db') ?? '';
  const traceTable = route.params.get('table') ?? '';
  const traceStart = route.params.get('start') ?? '';
  const traceEnd = route.params.get('end') ?? '';

  const onGenRollback = (): void => {
    const ids = rollbackTarget.map((c) => c.changeId).join(',');
    navigate(
      `/rollback${buildQuery({
        db: traceDb,
        table: traceTable,
        types: typeFilter,
        start: traceStart,
        end: traceEnd,
        ids,
      })}`,
    );
  };

  return (
    <div>
      <TopBar
        status={deriveTopStatus(state)}
        agentUrl={agentUrl}
        onAgentUrlChange={(url, reachable) => {
          dispatch({ type: 'setAgentUrl', url });
          if (reachable === false) dispatch({ type: 'setStatus', status: 'error' });
        }}
        left={
          <button type="button" className="btn btn-ghost" style={{ padding: 'var(--spacing-xs)' }} onClick={() => navigate('/trace')} aria-label="返回工单页">
            <ArrowLeft size={16} aria-hidden="true" />
          </button>
        }
        context={`${traceDb}.${traceTable} · ${formatLocalInput(traceStart)} – ${formatLocalInput(traceEnd)}`}
      />
      <main className="page">
        <div className="summary-bar">
          <span className="code">
            {traceDb}.{traceTable || '全部'}
          </span>
          <span className="num">{formatLocalInput(traceStart)} ~ {formatLocalInput(traceEnd)}</span>
          <span>
            共 <span className="num">{formatCount(filtered.length)}</span> 条变更
          </span>
          {demoMode ? <span className="text-secondary">演示数据</span> : null}
        </div>

        {parseStatus === 'error' ? (
          <div className="error-banner">{parseError}</div>
        ) : null}

        {changes.length === 0 ? (
          <div className="card">
            <EmptyState
              icon={SearchX}
              title="指定时间范围内未检测到 DML 变更"
              body="可能原因：该表无写入、Binlog 已过期清理、或时间范围过窄。可返回工单页扩大范围重试。"
            />
          </div>
        ) : (
          <>
            <div className="filter-bar">
              <div className="filter-pills">
                {TYPE_OPTIONS.map((opt) => (
                  <button
                    key={opt.value}
                    type="button"
                    className={`filter-pill ${typeFilter === opt.value ? `active-${opt.value}` : ''}`}
                    onClick={() => setTypeFilter(opt.value)}
                  >
                    {opt.label}
                  </button>
                ))}
              </div>
              <MultiSelect
                id="table-filter-ms"
                ariaLabel="按表筛选"
                value={tableFilter}
                onChange={setTableFilter}
                exclusiveOption="全部"
                options={allTables.map((t) => ({ value: t, label: t }))}
              />
              <MultiSelect
                id="column-filter-ms"
                ariaLabel="按列筛选"
                value={columnFilter}
                onChange={setColumnFilter}
                options={columnOptions}
                groups={columnGroups}
                placeholder="按列筛选"
                className="select-sm"
              />
              <span className="filter-match">
                匹配 <span className="num">{formatCount(filtered.length)}</span> 条
                {checkedIds.size > 0 ? <span className="filter-selected"> · 已勾选 <span className="num">{checkedIds.size}</span> 条</span> : null}
              </span>
            </div>

            <div className="data-table-wrap" onKeyDown={onKeyDown} tabIndex={0} role="grid" aria-label="变更列表">
              <table className="data-table">
                <thead>
                  <tr>
                    <th className="th-check">
                      <input type="checkbox" aria-label="全选当前页" checked={pageRows.length > 0 && pageRows.every((c) => checkedIds.has(c.changeId))} onChange={togglePageRows} />
                    </th>
                    <th>#</th>
                    <th>类型</th>
                    <th>库.表</th>
                    <th>操作时间</th>
                    <th>主键</th>
                    <th>变更列</th>
                    <th>操作</th>
                  </tr>
                </thead>
                <tbody>
                  {pageRows.map((c, idx) => {
                    const pk = primaryKey(c);
                    const pkValue = (c.newValues?.[pk] ?? c.oldValues?.[pk]) ?? '';
                    const isChecked = checkedIds.has(c.changeId);
                    return (
                      <tr
                        key={c.changeId}
                        className={`tr-${c.type} ${selected === idx ? 'tr-selected' : ''}`}
                        onClick={() => setDetail(c)}
                        role="row"
                        aria-selected={selected === idx}
                      >
                        <td className="td-check" onClick={(e) => e.stopPropagation()}>
                          <input type="checkbox" aria-label={`勾选 ${c.changeId}`} checked={isChecked} onChange={() => toggleRow(c.changeId)} />
                        </td>
                        <td className="cell-num">{(safePage - 1) * PAGE_SIZE + idx + 1}</td>
                        <td>
                          <TypePill type={c.type} />
                        </td>
                        <td className="cell-code">
                          {c.schema}.{c.table}
                        </td>
                        <td className="cell-num">{formatTime(epochMs(c.timestamp))}</td>
                        <td className="cell-code">
                          {pk}={pkValue}
                        </td>
                        <td className="cell-code col-cells" title={changedColumns(c).join(', ')}>
                          {changedColumns(c).slice(0, 4).join(', ')}{changedColumns(c).length > 4 ? ` …(+${changedColumns(c).length - 4})` : ''}
                        </td>
                        <td>
                          <button type="button" className="link-btn" onClick={(e) => { e.stopPropagation(); setDetail(c); }}>
                            明细
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
              <div className="pagination">
                <span className="text-secondary">
                  第 {safePage} / {pageCount} 页 · {PAGE_SIZE} 条/页
                </span>
                <button type="button" className="page-btn" disabled={safePage <= 1} onClick={() => goto(safePage - 1)}>
                  上一页
                </button>
                {Array.from({ length: Math.min(5, pageCount) }, (_, i) => {
                  const base = Math.max(1, Math.min(safePage - 2, pageCount - 4));
                  const p = base + i;
                  return (
                    <button key={p} type="button" className={`page-btn ${p === safePage ? 'current' : ''}`} onClick={() => goto(p)}>
                      {p}
                    </button>
                  );
                })}
                <button type="button" className="page-btn" disabled={safePage >= pageCount} onClick={() => goto(safePage + 1)}>
                  下一页
                </button>
              </div>
            </div>

            {filtered.length > 0 ? (
              <Button
                className="fab"
                onClick={onGenRollback}
              >
                <WandSparkles size={16} aria-hidden="true" />
                生成回滚脚本{checkedIds.size > 0 ? ` (${checkedIds.size})` : ''}
              </Button>
            ) : null}
          </>
        )}
      </main>

      {detail ? <ChangeDetailModal change={detail} onClose={() => setDetail(null)} /> : null}
    </div>
  );
}
