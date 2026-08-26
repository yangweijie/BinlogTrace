// Input.tsx — 表单输入（label + error + 右侧插槽，如密码可见切换）
import type { InputHTMLAttributes, ReactNode } from 'react';

interface Props extends InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  error?: string;
  invalid?: boolean;
  hint?: string;
  rightSlot?: ReactNode;
}

export default function Input({ label, error, invalid = false, hint, rightSlot, className = '', id, ...rest }: Props) {
  const inputId = id ?? (label ? `in-${label}` : undefined);
  return (
    <div className="field">
      {label ? (
        <label className="field-label" htmlFor={inputId}>
          {label}
        </label>
      ) : null}
      <div className="input-wrap">
        <input
          id={inputId}
          className={`input ${invalid ? 'invalid' : ''} ${className}`.trim()}
          aria-invalid={invalid || undefined}
          {...rest}
        />
        {rightSlot}
      </div>
      {hint ? <div className="field-hint">{hint}</div> : null}
      <div className="field-error" role="alert">
        {error ?? ''}
      </div>
    </div>
  );
}
