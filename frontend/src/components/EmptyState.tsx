// EmptyState.tsx — 空态/引导（具体文案由调用方传入，禁止占位）
import type { LucideIcon } from 'lucide-react';

interface Props {
  icon: LucideIcon;
  title: string;
  body: string;
}

export default function EmptyState({ icon: Icon, title, body }: Props) {
  return (
    <div className="empty-state">
      <Icon size={24} aria-hidden="true" style={{ color: 'var(--color-textSecondary)' }} />
      <p className="empty-title" style={{ marginTop: 'var(--spacing-sm)' }}>
        {title}
      </p>
      <p>{body}</p>
    </div>
  );
}
