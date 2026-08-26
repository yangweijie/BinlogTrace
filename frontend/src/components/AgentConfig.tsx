// AgentConfig.tsx — 右上角代理配置（地址 + ping 检测）
import { useState, useCallback, useEffect } from 'react';
import { Settings, Loader2 } from 'lucide-react';

const STORAGE_KEY = 'dms-agent-url';
const DEFAULT_URL = 'http://127.0.0.1:8080';

/** 从 localStorage 读取持久化的代理地址 */
export function getStoredAgentUrl(): string {
  try {
    return localStorage.getItem(STORAGE_KEY) || DEFAULT_URL;
  } catch {
    return DEFAULT_URL;
  }
}

/** 持久化代理地址 */
function storeAgentUrl(url: string): void {
  try {
    localStorage.setItem(STORAGE_KEY, url);
  } catch { /* noop */ }
}

interface Props {
  /** 当前代理地址（受控） */
  value: string;
  /** 地址变更回调；第二个参数为确定时自动 ping 的可达性结果 */
  onChange: (url: string, reachable?: boolean) => void;
}

/** 对代理地址发起 ping，返回是否可达 */
export async function pingAgent(url: string): Promise<boolean> {
  const u = url.replace(/\/+$/, '');
  try {
    const res = await fetch(`${u}/ping`, { method: 'GET', signal: AbortSignal.timeout(5000) });
    return res.ok;
  } catch {
    return false;
  }
}

export default function AgentConfig({ value, onChange }: Props) {
  const [open, setOpen] = useState(false);
  const [input, setInput] = useState(value);
  const [checking, setChecking] = useState(false);

  // 外部 value 变化时同步 input（如其他地方重置）
  useEffect(() => {
    setInput(value);
  }, [value]);

  const handleSave = useCallback(async () => {
    const trimmed = input.trim() || DEFAULT_URL;
    setInput(trimmed);
    storeAgentUrl(trimmed);
    setChecking(true);
    const reachable = await pingAgent(trimmed);
    setChecking(false);
    onChange(trimmed, reachable);
    setOpen(false);
  }, [input, onChange]);

  return (
    <div className="agent-config">
      <button
        className="agent-config-trigger"
        onClick={() => setOpen((v) => !v)}
        title="代理配置"
        aria-label="代理配置"
        type="button"
      >
        <Settings size={18} />
      </button>

      {open && (
        <div className="agent-config-popover">
          <div className="agent-config-header">代理服务配置</div>
          <div className="agent-config-body">
            <label className="agent-config-label" htmlFor="agent-url-input">
              代理地址
            </label>
            <input
              id="agent-url-input"
              className="agent-config-input"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && handleSave()}
              placeholder={DEFAULT_URL}
              autoFocus
            />
            <div className="agent-config-actions">
              <button className="btn btn-sm btn-primary" type="button" onClick={handleSave} disabled={checking}>
                {checking ? <Loader2 size={14} className="spin" /> : null}
                确定
              </button>
            </div>
            <div className="agent-config-hint">
              点击确定将自动检测代理连通性并刷新状态
            </div>
          </div>
        </div>
      )}

      {/* 点击外部关闭 */}
      {open && <div className="agent-config-backdrop" onClick={() => setOpen(false)} />}
    </div>
  );
}
