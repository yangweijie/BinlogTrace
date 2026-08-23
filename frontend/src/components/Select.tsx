// Select.tsx — 下拉选择（label + 选项；空态引导占位）
import type { SelectHTMLAttributes } from 'react';

interface Option {
  value: string;
  label: string;
}

interface Props extends SelectHTMLAttributes<HTMLSelectElement> {
  label?: string;
  error?: string;
  options: Option[];
  placeholder?: string;
}

export default function Select({ label, error, options, placeholder, className = '', id, ...rest }: Props) {
  const selectId = id ?? (label ? `sel-${label}` : undefined);
  return (
    <div className="field">
      {label ? (
        <label className="field-label" htmlFor={selectId}>
          {label}
        </label>
      ) : null}
      <select id={selectId} className={`select ${className}`.trim()} {...rest}>
        {placeholder !== undefined ? <option value="">{placeholder}</option> : null}
        {options.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </select>
      <div className="field-error" role="alert">
        {error ?? ''}
      </div>
    </div>
  );
}
