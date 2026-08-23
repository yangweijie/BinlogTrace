// Spinner.tsx — 加载指示（lucide LoaderCircle，16px，尊重 reduced-motion）
import { LoaderCircle } from 'lucide-react';

export default function Spinner({ size = 16 }: { size?: number }) {
  return <LoaderCircle className="spin" size={size} aria-hidden="true" />;
}
