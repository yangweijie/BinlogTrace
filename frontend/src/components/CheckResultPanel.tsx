// CheckResultPanel.tsx — 前置检查结果区（逐项展示，每项可展开"如何修复"，GRANT/my.cnf 可复制）
import { useState } from 'react';
import { TriangleAlert, CircleCheck, ChevronDown, ChevronRight, Wrench } from 'lucide-react';
import type { CheckResult, CheckIssue } from '../types/api';
import CodeBlock from './CodeBlock';

function CheckItem({ issue, tone }: { issue: CheckIssue; tone: 'danger' | 'warn' }) {
  const [open, setOpen] = useState(false);
  return (
    <div className="check-item">
      <div className="check-item-head">
        {tone === 'danger' ? (
          <TriangleAlert size={16} aria-hidden="true" />
        ) : (
          <Wrench size={16} aria-hidden="true" />
        )}
        <span className="check-item-title">{issue.message}</span>
        {issue.fix ? (
          <button
            type="button"
            className="link-btn"
            aria-expanded={open}
            onClick={() => setOpen((v) => !v)}
          >
            {open ? (
              <ChevronDown size={16} aria-hidden="true" />
            ) : (
              <ChevronRight size={16} aria-hidden="true" />
            )}
            如何修复
          </button>
        ) : null}
      </div>
      {open && issue.fix ? (
        <div className="check-item-fix">
          <div className="check-item-fix-title">{issue.fix.title}</div>
          <CodeBlock lines={issue.fix.lines} />
          {issue.fix.note ? <p className="text-secondary" style={{ marginTop: 'var(--spacing-xs)' }}>{issue.fix.note}</p> : null}
        </div>
      ) : null}
    </div>
  );
}

interface Props {
  result: CheckResult | null;
  compact?: boolean;
}

export default function CheckResultPanel({ result, compact = false }: Props) {
  if (!result) return null;
  const hasError = result.errors.length > 0;
  const hasWarn = result.warnings.length > 0;
  const tone = hasError ? 'danger' : hasWarn ? 'warn' : 'ok';

  return (
    <div className={`check-panel check-panel-${tone}`} role={hasError ? 'alert' : 'status'}>
      <div className="check-item-head" style={{ marginBottom: 'var(--spacing-xs)' }}>
        {hasError ? (
          <TriangleAlert size={16} aria-hidden="true" />
        ) : hasWarn ? (
          <Wrench size={16} aria-hidden="true" />
        ) : (
          <CircleCheck size={16} aria-hidden="true" />
        )}
        <span className="check-item-title">
          {hasError
            ? `前置检查未通过：${result.errors.length} 项阻断项需修复`
            : hasWarn
              ? `前置检查通过但有 ${result.warnings.length} 项降级警告，可继续`
              : '前置检查通过：Binlog 配置与权限满足追踪要求'}
        </span>
      </div>
      {!compact || hasError ? (
        <>
          {result.errors.map((issue, i) => (
            <CheckItem key={`e-${i}`} issue={issue} tone="danger" />
          ))}
          {result.warnings.map((issue, i) => (
            <CheckItem key={`w-${i}`} issue={issue} tone="warn" />
          ))}
        </>
      ) : null}
    </div>
  );
}
