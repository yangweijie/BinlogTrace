// binlog.d.ts — WASM 边界契约（对应 04-架构细化 §2/§4）与变更 Schema

/** parse_binlog 输入：事件数组 + 元数据快照 */
export interface ParseEventsInput {
  events: Array<{
    type: 'table_map' | 'write_rows' | 'update_rows' | 'delete_rows' | 'xid';
    rawBase64: string;
    binlogFile: string;
    binlogPos: number;
    timestamp: number;
    serverId: number;
  }>;
  metadata: {
    database?: string;
    tables: Record<string, { columns: Array<Record<string, unknown>> }>;
  };
}

/** 标准化变更（parse_binlog 输出项） */
export interface Change {
  changeId: string;
  schema: string;
  table: string;
  type: 'insert' | 'update' | 'delete';
  columns: string[];
  /** 主键列名；用于回滚 WHERE 定位（更新场景）。空数组 = 无主键 */
  primaryKeys: string[];
  oldValues: Record<string, string | null> | null;
  newValues: Record<string, string | null> | null;
  xid: number;
  timestamp: number;
  binlogFile: string;
  binlogPos: number;
}

export interface ParseResult {
  ok: boolean;
  changes: Change[];
  warnings: string[];
  error?: string;
}

/** generate_rollback 输出 */
export interface RollbackResult {
  ok: boolean;
  sql: string;
  stats: { statements: number; transactions: number };
  error?: string;
}

/** check_binlog_cfg 输入（来自 connected 回传 + 权限探测） */
export interface CheckMetaInput {
  serverVersion: string;
  hasBinlog: boolean;
  binlogFormat: string;
  binlogRowImage: string;
  userPrivileges: string[];
}
