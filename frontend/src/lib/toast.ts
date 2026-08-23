// toast.ts — 轻量 Toast（2s 自动消失，无依赖）
let host: HTMLDivElement | null = null;

function ensureHost(): HTMLDivElement {
  if (host === null || !document.body.contains(host)) {
    host = document.createElement('div');
    host.setAttribute('data-toast-host', 'true');
    document.body.appendChild(host);
  }
  return host;
}

export function toast(message: string): void {
  const el = document.createElement('div');
  el.className = 'toast';
  el.textContent = message;
  el.setAttribute('role', 'status');
  ensureHost().appendChild(el);
  window.setTimeout(() => {
    el.remove();
  }, 2000);
}
