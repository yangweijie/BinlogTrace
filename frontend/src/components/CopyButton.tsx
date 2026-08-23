// CopyButton.tsx — 复制按钮（lucide Copy；2s 成功反馈）
import { useState, type ButtonHTMLAttributes } from 'react';
import { Copy, Check } from 'lucide-react';

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
  text: string;
  label?: string;
}

async function copyText(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      const ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return ok;
    } catch {
      return false;
    }
  }
}

export default function CopyButton({ text, label = '复制', className = '', ...rest }: Props) {
  const [copied, setCopied] = useState(false);
  return (
    <button
      type="button"
      className={`btn btn-ghost ${className}`.trim()}
      onClick={async () => {
        const ok = await copyText(text);
        if (ok) {
          setCopied(true);
          window.setTimeout(() => setCopied(false), 2000);
        }
      }}
      aria-label={label}
      {...rest}
    >
      {copied ? <Check size={16} aria-hidden="true" /> : <Copy size={16} aria-hidden="true" />}
      {copied ? '已复制' : label}
    </button>
  );
}
