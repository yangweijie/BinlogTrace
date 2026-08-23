// Card.tsx — 卡片容器（--color-surface + --radius-md + --shadow-card）
import type { ReactNode } from 'react';

interface Props {
  title?: string;
  children: ReactNode;
  className?: string;
}

export default function Card({ title, children, className = '' }: Props) {
  return (
    <section className={`card card-pad ${className}`.trim()}>
      {title ? <h2 className="card-title">{title}</h2> : null}
      {children}
    </section>
  );
}
