// check-meta.ts — connected 载荷 → check_binlog_cfg 输入（含权限探测字段，AC-13）
import type { ConnectedPayload } from '../types/api';

export function toCheckMeta(meta: ConnectedPayload): string {
  return JSON.stringify({
    serverVersion: meta.serverVersion,
    hasBinlog: meta.hasBinlog,
    binlogFormat: meta.binlogFormat,
    binlogRowImage: meta.binlogRowImage,
    userPrivileges: meta.userPrivileges,
  });
}
