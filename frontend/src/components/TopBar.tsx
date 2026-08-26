// TopBar.tsx — 顶栏（品牌 + 代理状态 + 上下文/返回）
import type { ReactNode } from 'react';
import { Database } from 'lucide-react';
import StatusDot from './StatusDot';

interface Props {
  status: 'connected' | 'idle' | 'error' | 'demo';
  context?: ReactNode;
  left?: ReactNode;
}

export default function TopBar({ status, context, left }: Props) {
  return (
    <header className="topbar">
      <div className="topbar-brand">
        {left}
        <Database size={20} aria-hidden="true" style={{ color: 'var(--color-primary)' }} />
        <span>BinlogTrace · MySQL 数据追踪</span>
      </div>
      <div className="topbar-right">
        {context ? <span className="topbar-context">{context}</span> : null}
        {status === 'connected' ? (
          <StatusDot tone="ok" text="代理已连接" />
        ) : status === 'error' ? (
          <StatusDot tone="err" text="代理异常" />
        ) : status === 'demo' ? (
          <StatusDot tone="info" text="演示模式" />
        ) : (
          <StatusDot tone="muted" text="代理未连接" />
        )}
      </div>
    </header>
  );
}
