// Checkbox.tsx — 勾选项（可选类型色点；全不选时由父级报错）
import type { ChangeType } from '../types/api';

interface Props {
  label: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
  dotClass?: string;
  disabled?: boolean;
}

export default function Checkbox({ label, checked, onChange, dotClass, disabled = false }: Props) {
  return (
    <label className={`checkbox-row ${disabled ? 'disabled' : ''}`.trim()}>
      <input
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(ev) => onChange(ev.target.checked)}
      />
      {dotClass ? <span className={`type-dot ${dotClass}`} aria-hidden="true" /> : null}
      {label}
    </label>
  );
}

export const TYPE_DOTS: Record<ChangeType, string> = {
  insert: 'type-dot-insert',
  update: 'type-dot-update',
  delete: 'type-dot-delete',
};

export const TYPE_LABELS: Record<ChangeType, string> = {
  insert: 'INSERT',
  update: 'UPDATE',
  delete: 'DELETE',
};
