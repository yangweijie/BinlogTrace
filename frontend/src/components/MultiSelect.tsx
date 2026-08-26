// MultiSelect.tsx — 带勾选面板的多选下拉（筛选用；与 Select 风格一致）
import { useEffect, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';

interface OptionGroup {
  label: string;
  options: string[];
}

interface Props {
  value: string[];
  onChange: (value: string[]) => void;
  options?: string[];
  groups?: OptionGroup[];
  placeholder?: string;
  className?: string;
  ariaLabel?: string;
}

export default function MultiSelect({ value, onChange, options = [], groups = [], placeholder = '全部', className = '', ariaLabel }: Props) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent): void => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, [open]);

  const all = value.length === 0;
  const label = all ? placeholder : `${value.length} 项`;

  const toggle = (opt: string): void => {
    onChange(value.includes(opt) ? value.filter((v) => v !== opt) : [...value, opt]);
  };

  const renderOptions = (list: string[]): React.ReactNode =>
    list.map((opt) => (
      <label key={opt} className="multi-select-opt">
        <input type="checkbox" checked={value.includes(opt)} onChange={() => toggle(opt)} />
        <span>{opt}</span>
      </label>
    ));

  const hasOptions = options.length > 0 || groups.length > 0;

  return (
    <div className={`multi-select ${className}`.trim()} ref={rootRef}>
      <button
        type="button"
        className={`multi-select-trigger ${all ? '' : 'has-value'}`}
        onClick={() => setOpen((o) => !o)}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label={ariaLabel}
      >
        <span>{label}</span>
        <ChevronDown size={14} aria-hidden="true" />
      </button>
      {open ? (
        <div className="multi-select-panel">
          {!hasOptions ? (
            <div className="multi-select-empty">无可用选项</div>
          ) : (
            <>
              {renderOptions(options)}
              {groups.map((g) => (
                <div key={g.label} className="multi-select-group">
                  <div className="multi-select-group-label">{g.label}</div>
                  {renderOptions(g.options)}
                </div>
              ))}
            </>
          )}
        </div>
      ) : null}
    </div>
  );
}
