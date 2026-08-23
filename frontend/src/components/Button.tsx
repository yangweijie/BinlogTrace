// Button.tsx — 按钮（Default/Hover/Focus/Active/Disabled/Loading 六态，Token 驱动）
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { LoaderCircle } from 'lucide-react';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger-ghost';

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  loading?: boolean;
  block?: boolean;
  children: ReactNode;
}

export default function Button({
  variant = 'primary',
  loading = false,
  block = false,
  disabled,
  children,
  className = '',
  type = 'button',
  ...rest
}: Props) {
  return (
    <button
      type={type}
      className={`btn btn-${variant} ${block ? 'btn-block' : ''} ${className}`.trim()}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      {...rest}
    >
      {loading ? <LoaderCircle className="spin" size={16} aria-hidden="true" /> : null}
      {children}
    </button>
  );
}
