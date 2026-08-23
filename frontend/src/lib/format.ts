// format.ts — 时间/数值/错误格式化（值展示统一走 --ff-data / 文本直出）

/** 时间戳 → 'YYYY-MM-DD HH:mm:ss.SSS'（本地时区） */
export function formatTimestamp(ts: number): string {
  const d = new Date(ts);
  const pad = (n: number, len = 2): string => String(n).padStart(len, '0');
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ` +
    `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}.${pad(d.getMilliseconds(), 3)}`
  );
}

/** 时间戳 → 'YYYY-MM-DD HH:mm:ss' */
export function formatTime(ts: number): string {
  const d = new Date(ts);
  const pad = (n: number): string => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

/** datetime-local 输入值 → Date（兼容 'YYYY-MM-DDTHH:mm' 与 'YYYY-MM-DD HH:mm'） */
export function parseLocal(dt: string): Date {
  const normalized = dt.replace(' ', 'T');
  const d = new Date(normalized);
  return Number.isNaN(d.getTime()) ? new Date() : d;
}

/** Date → datetime-local 输入值 */
export function toLocalInput(d: Date): string {
  const pad = (n: number): string => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** 距当前 N 小时的 datetime-local 输入值 */
export function hoursAgo(hours: number): string {
  return toLocalInput(new Date(Date.now() - hours * 3600_000));
}

/** 事件时间戳 → 毫秒（binlog 事件时间为 epoch 秒，自动归一化到毫秒；已为毫秒的不动） */
export function epochMs(ts: number): number {
  return ts > 1e11 ? ts : ts * 1000;
}

/** datetime-local 值展示：去掉 'T' 分隔（用于 URL 参数/输入框直读），补秒、补秒字段为 00 */
export function formatLocalInput(dt: string): string {
  if (!dt) return '';
  const withSpace = dt.replace('T', ' ');
  // 'YYYY-MM-DD HH:mm' → 补 ':00'
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(withSpace)) return `${withSpace}:00`;
  return withSpace;
}

/** 千分位（--ff-data 展示） */
export function formatCount(n: number): string {
  return n.toLocaleString('zh-CN');
}

/** 错误码 → 人类可读描述（协议 v2 §1.3） */
export function describeErrorCode(code: number): string {
  const map: Record<number, string> = {
    1001: '认证失败（Access denied）',
    1002: '网络不可达',
    1003: 'Binlog 未开启或格式不符',
    1004: '权限不足',
    1005: '事件解码失败',
    1006: '代理未就绪',
    1007: '请求超时',
    1008: '参数非法',
    1009: 'server-id 冲突',
    1010: '协议错误',
    1011: 'Binlog 位置无效',
    1012: '事务压缩无法解码',
    1013: '元数据缺失',
  };
  return map[code] ?? `未知错误 ${code}`;
}

/** 将任意值转为展示字符串（AC-12 精度保真：BIGINT/DECIMAL 直出，NULL 特殊） */
export function valueToText(v: string | null | undefined): string {
  if (v === null || v === undefined) return 'NULL';
  return v;
}

export function isNullValue(v: string | null | undefined): boolean {
  return v === null || v === undefined;
}
