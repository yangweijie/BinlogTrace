// MultiSelect.tsx — 带勾选面板的多选下拉（筛选用；与 Select 风格一致）
import { useEffect, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';

interface OptionItem {
  value: string;
  label: string;
}
type Option = string | OptionItem;

interface OptionGroup {
  label: string;
  options: Option[];
}

interface Props {
  value: string[];
  onChange: (value: string[]) => void;
  options?: Option[];
  groups?: OptionGroup[];
  label?: string;
  id?: string;
  placeholder?: string;
  className?: string;
  ariaLabel?: string;
  /** 设为此值的选项与其他选项互斥（如"全部"） */
  exclusiveOption?: string;
}

function optValue(o: Option): string {
  return typeof o === 'string' ? o : o.value;
}
function optLabel(o: Option): string {
  return typeof o === 'string' ? o : o.label;
}

export default function MultiSelect({ value, onChange, options = [], groups = [], label, id, placeholder = '全部', className = '', ariaLabel, exclusiveOption }: Props) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const selectId = id ?? (label ? `ms-${label}` : undefined);

  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent): void => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, [open]);

  const all = value.length === 0;
  const displayLabel = all ? placeholder : `${value.length} 项`;

  const toggle = (opt: string): void => {
    if (exclusiveOption != null && opt === exclusiveOption) {
      // 点击互斥选项（如"全部"）：只保留它
      onChange(value.length === 1 && value[0] === exclusiveOption ? [] : [exclusiveOption]);
      return;
    }
    const next = value.includes(opt)
      ? value.filter((v) => v !== opt)
      : [...value, opt];
    // 选非互斥项时，移除互斥项
    if (exclusiveOption != null) {
      const filtered = next.filter((v) => v !== exclusiveOption);
      onChange(filtered.length === 0 ? [exclusiveOption] : filtered);
      return;
    }
    onChange(next);
  };

  const renderOptions = (list: Option[]): React.ReactNode =>
    list.map((opt) => {
      const v = optValue(opt);
      return (
        <label key={v} className="multi-select-opt">
          <input type="checkbox" checked={value.includes(v)} onChange={() => toggle(v)} />
          <span>{optLabel(opt)}</span>
        </label>
      );
    });

  const hasOptions = options.length > 0 || groups.length > 0;

  const trigger = (
    <button
      type="button"
      className={`multi-select-trigger ${all ? '' : 'has-value'}`}
      onClick={() => setOpen((o) => !o)}
      aria-haspopup="listbox"
      aria-expanded={open}
      aria-label={ariaLabel ?? label}
    >
      <span>{displayLabel}</span>
      <ChevronDown size={14} aria-hidden="true" />
    </button>
  );

  const panel = open ? (
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
  ) : null;

  if (label) {
    return (
      <div className="field" ref={rootRef}>
        <label className="field-label" htmlFor={selectId}>{label}</label>
        <div id={id} className={`multi-select ${className}`.trim()}>
          {trigger}
          {panel}
        </div>
      </div>
    );
  }

  return (
    <div id={id} className={`multi-select ${className}`.trim()} ref={rootRef}>
      {trigger}
      {panel}
    </div>
  );
}
