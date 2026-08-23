// StatusDot.tsx — 状态点 + 文案（连接/代理状态徽标）
type Tone = 'ok' | 'muted' | 'err';

interface Props {
  tone: Tone;
  text: string;
}

export default function StatusDot({ tone, text }: Props) {
  return (
    <span className="badge">
      <span className={`status-dot status-${tone}`} aria-hidden="true" />
      {text}
    </span>
  );
}
