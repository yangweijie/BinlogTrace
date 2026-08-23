// TypePill.tsx — 变更类型 pill（1px 边框 + 同色文字，不新增背景 Token）
import type { ChangeType } from '../types/api';
import { TYPE_LABELS } from './Checkbox';

interface Props {
  type: ChangeType;
}

export default function TypePill({ type }: Props) {
  return <span className={`pill pill-${type}`}>{TYPE_LABELS[type]}</span>;
}
