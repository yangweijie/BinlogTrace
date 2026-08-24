// api.d.ts — 前后端共享契约：WS 协议 v2 + 本地存储（对应 04-架构细化 §1）

/** 已保存连接（localStorage: saved_connections） */
export interface SavedConnection {
  id: string;
  name: string;
  host: string;
  port: number;
  user: string;
  password?: string;
  database?: string;
}

/** 连接表单原始输入（密码仅勾选"保存到本地"才写入 SavedConnection） */
export interface ConnectionForm {
  name: string;
  host: string;
  port: string;
  user: string;
  password: string;
  database: string;
  saveLocally: boolean;
  useDemo: boolean;
}

/** WS 统一帧（v2） */
export interface WsFrame<T = unknown> {
  v: 2;
  id: string;
  type: string;
  ts: number;
  payload: T;
}

/** connect 载荷（浏览器→代理） */
export interface ConnectPayload {
  host: string;
  port: number;
  user: string;
  password: string;
  database?: string;
  serverId?: number;
  connectTimeoutMs?: number;
}

/** connected 载荷（代理→浏览器），含前置检查字段 */
export interface ConnectedPayload {
  ok: boolean;
  serverVersion: string;
  binlogFile: string | null;
  binlogPos: number | null;
  binlogFormat: string;
  binlogRowImage: string;
  hasBinlog: boolean;
  serverId: number;
  /** 权限探测结果（代理探测后回传；缺省空数组=未知） */
  userPrivileges: string[];
}

/** binlog-dump 载荷 */
export interface BinlogDumpPayload {
  binlogFile: string;
  binlogPos: number;
  slaveFlags: number;
}

/** binlog-event 载荷（透传原始事件，demo/旧链路） */
export interface BinlogEventPayload {
  raw: string;
  eventType: number;
  binlogFile: string;
  binlogPos: number;
  timestamp: number;
  serverId: number;
}

/** binlog-change 载荷（agent 侧 krowinski 解析后的结构化变更，真实模式） */
export interface BinlogChangePayload {
  kind: 'insert' | 'update' | 'delete';
  schema: string;
  table: string;
  columns: string[];
  /** 主键列名（代理按 krowinski 字段元数据 COLUMN_KEY='PRI' 判定） */
  primaryKeys: string[];
  before: Record<string, string | number | null> | null;
  after: Record<string, string | number | null> | null;
  xid: number;
  timestamp: number;
  binlogFile: string;
  binlogPos: number;
}

/** query 载荷 */
export interface QueryPayload {
  sql: string;
  database?: string;
}

/** query-result 载荷 */
export interface QueryResultPayload {
  columns: Array<{ name: string; type: string }>;
  rows: Array<Record<string, unknown>>;
  affectedRows?: number;
}

/** error 载荷 */
export interface ErrorPayload {
  code: number;
  message: string;
  detail?: string;
}

/** heartbeat 载荷 */
export interface HeartbeatPayload {
  ts: number;
  binlogPos?: number;
}

/** 错误码表（协议 v2 §1.3） */
export const WS_ERROR: Record<number, string> = {
  1001: '认证失败（Access denied）',
  1002: '网络不可达',
  1003: 'Binlog 未开启',
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

/** 追踪工单配置（localStorage: last_trace_config + URL） */
export type ChangeType = 'insert' | 'update' | 'delete';

export interface TraceConfig {
  db: string;
  table: string; // '全部' 或表名
  start: string; // datetime-local 值
  end: string;
  types: ChangeType[];
}

/** 前置检查结果（check_binlog_cfg 输出） */
export interface CheckIssue {
  code: number;
  message: string;
  fix?: FixGuide;
}

export type FixKind = 'mycnf' | 'grant' | 'dynamic' | 'tip';

export interface FixGuide {
  kind: FixKind;
  title: string;
  lines: string[];
  note?: string;
}

export interface CheckResult {
  ok: boolean;
  errors: CheckIssue[];
  warnings: CheckIssue[];
}
