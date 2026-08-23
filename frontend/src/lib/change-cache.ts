// change-cache.ts — 解析结果 sessionStorage 缓存（刷新恢复，URL 参数 + 缓存双保险）
import type { Change } from '../types/binlog';

const KEY = 'trace_changes';

export function saveChanges(changes: Change[]): void {
  try {
    sessionStorage.setItem(KEY, JSON.stringify(changes));
  } catch {
    /* 容量超限时忽略，仅影响刷新恢复 */
  }
}

export function loadChanges(): Change[] | null {
  try {
    const raw = sessionStorage.getItem(KEY);
    return raw === null ? null : (JSON.parse(raw) as Change[]);
  } catch {
    return null;
  }
}

export function clearChanges(): void {
  try {
    sessionStorage.removeItem(KEY);
  } catch {
    /* ignore */
  }
}
