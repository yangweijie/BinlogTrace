// ChangeDetailModal.tsx — 变更明细弹窗（AC-12 前后值 diff；UPDATE 变更行高亮、INSERT/DELETE 缺失侧虚线）
import { useState } from 'react';
import { X, ChevronDown } from 'lucide-react';
import TypePill from './TypePill';
import Button from './Button';
import { formatTimestamp, epochMs } from '../lib/format';
import { navigate } from '../lib/route';
import type { Change } from '../types/binlog';

const MAX_LEN = 80;

function findPk(change: Change): string {
  const idCol = change.columns.find((c) => c.toLowerCase() === 'id');
  return idCol ?? change.columns[0] ?? '';
}

function isChanged(a: string | null | undefined, b: string | null | undefined): boolean {
  return a !== b;
}

function CellValue({ value, missing }: { value: string | null | undefined; missing: boolean }) {
  const [open, setOpen] = useState(false);
  if (missing) return <span className="diff-missing">—</span>;
  if (value === null || value === undefined) return <span className="diff-null">NULL</span>;
  const text = String(value);
  const showMore = text.length > MAX_LEN;
  const shown = open || !showMore ? text : `${text.slice(0, MAX_LEN)}…`;
  return (
    <span>
      <span className={showMore && !open ? 'truncate-text' : ''}>{shown}</span>
      {showMore ? (
        <button type="button" className="diff-expand" onClick={() => setOpen((v) => !v)}>
          <ChevronDown size={12} aria-hidden="true" />
          {open ? '收起' : '展开'}
        </button>
      ) : null}
    </span>
  );
}

interface Props {
  change: Change;
  onClose: () => void;
}

export default function ChangeDetailModal({ change, onClose }: Props) {
  const pk = findPk(change);
  const isUpdate = change.type === 'update';
  const oldValues = change.oldValues ?? {};
  const newValues = change.newValues ?? {};
  const pkValue = (newValues[pk] ?? oldValues[pk]) ?? '';

  const close = (): void => onClose();

  return (
    <div
      className="modal-mask"
      role="dialog"
      aria-modal="true"
      aria-label={`变更明细 ${change.schema}.${change.table}`}
      onClick={(e) => {
        if (e.target === e.currentTarget) close();
      }}
    >
      <div className="modal-card">
        <div className="modal-head">
          <TypePill type={change.type} />
          <span className="modal-title">
            {change.schema}.{change.table}
          </span>
          <span className="num text-secondary">
            {formatTimestamp(epochMs(change.timestamp))}
          </span>
          <button type="button" className="modal-close" aria-label="关闭" onClick={close}>
            <X size={20} aria-hidden="true" />
          </button>
        </div>
        <div className="modal-meta">
          <span>事务 {change.xid}</span>
          <span>
            主键 {pk}={pkValue}
          </span>
          <span>
            {change.binlogFile}:{change.binlogPos}
          </span>
        </div>
        <div className="modal-body">
          <table className="diff-table">
            <thead>
              <tr>
                <th>列名</th>
                <th>旧值</th>
                <th>新值</th>
              </tr>
            </thead>
            <tbody>
              {change.columns.map((col) => {
                const oldV = oldValues[col];
                const newV = newValues[col];
                const changed = isUpdate && isChanged(oldV, newV);
                return (
                  <tr key={col} className={changed ? 'diff-changed' : ''}>
                    <td>{col}</td>
                    <td>
                      <CellValue value={oldV} missing={change.type === 'insert'} />
                    </td>
                    <td>
                      <span className={changed ? 'diff-new' : ''}>
                        <CellValue value={newV} missing={change.type === 'delete'} />
                      </span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
        <div className="modal-foot">
          <Button
            variant="secondary"
            onClick={() => navigate(`/rollback?changeId=${encodeURIComponent(change.changeId)}`)}
          >
            生成该行回滚
          </Button>
        </div>
      </div>
    </div>
  );
}
