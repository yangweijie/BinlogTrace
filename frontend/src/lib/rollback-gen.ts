// rollback-gen.ts — flashback 回滚 SQL 生成（TS 参考实现，WASM 未就绪时回退；算法与 Spec §3 一致）
// AC-06/07/08：INSERT→DELETE WHERE 全列=原值；UPDATE→SET 原值 WHERE 新值；DELETE→INSERT 原值
// AC-09：多事务按提交时刻逆序，事务内正序，事务包裹 START TRANSACTION/COMMIT

import type { Change } from '../types/binlog';

export interface RollbackOutput {
  ok: boolean;
  sql: string;
  stats: { statements: number; transactions: number };
  error?: string;
}

function isNumericLiteral(v: string): boolean {
  return /^-?\d+(\.\d+)?$/.test(v);
}

function isHexLiteral(v: string): boolean {
  return /^X'[0-9A-Fa-f]+'$/.test(v);
}

/** 值转义（SqlLiteral：数字原样、字符串单引号、NULL→NULL、二进制→X'..'） */
function literal(v: string | null): string {
  if (v === null) return 'NULL';
  if (isNumericLiteral(v) || isHexLiteral(v)) return v;
  return `'${v.replace(/\\/g, '\\\\').replace(/'/g, "''")}'`;
}

function whereClause(values: Record<string, string | null>, columns: string[]): string {
  return columns
    .map((c) => {
      const v = values[c];
      if (v === null) return `\`${c}\` IS NULL`;
      return `\`${c}\`=${literal(v)}`;
    })
    .join(' AND ');
}

/** 更新回滚：只 SET 变更列；WHERE 优先主键（before 值），无主键用未变更列；与 SET 同列且值相同则从 WHERE 忽略 */
function updateRollback(c: Change): string {
  const t = fullTable(c.schema, c.table);
  const old = c.oldValues ?? {};
  const after = c.newValues ?? {};
  const pks = (c.primaryKeys ?? []).filter((p) => old[p] !== undefined);

  // 只回滚 before/after 实际变化的列（其余列恢复后值不变，SET 为冗余）
  const changed = c.columns.filter((col) => old[col] !== after[col]);

  // WHERE 定位列（用 before 值）：优先主键；无主键则退回未变更列（值稳定可作定位依据）
  let whereCols: string[];
  if (pks.length > 0) {
    whereCols = pks;
  } else {
    whereCols = c.columns.filter((col) => old[col] === after[col] && old[col] !== undefined);
  }

  // 冗余过滤：取值相同的列同时出现在 SET 与 WHERE 时，WHERE 条件可忽略
  const setCols = new Set(changed);
  const finalCols = whereCols.filter((col) => !setCols.has(col));

  const setFields = changed.map((col) => `\`${col}\`=${literal(old[col])}`);

  if (finalCols.length === 0) {
    // 缺少安全定位列（如所有列都变化且无主键）：退回全列旧值，保证不误伤多行
    return `UPDATE ${t} SET ${setFields.join(',')}\nWHERE ${whereClause(old, c.columns)};`;
  }
  const whereFields = finalCols.map((col) => {
    const v = old[col];
    return v === null ? `\`${col}\` IS NULL` : `\`${col}\`=${literal(v)}`;
  });
  return `UPDATE ${t} SET ${setFields.join(',')}\nWHERE ${whereFields.join(' AND ')};`;
}

function colList(columns: string[]): string {
  return columns.map((c) => `\`${c}\``).join(',');
}

function fullTable(schema: string, table: string): string {
  return `\`${schema}\`.\`${table}\``;
}

/** 源 DML 概览（回滚注释用）：INSERT/UPDATE/DELETE 原始操作 + 实际变更值 */
function sourceDml(c: Change): string {
  const t = fullTable(c.schema, c.table);
  const old = c.oldValues ?? {};
  const after = c.newValues ?? {};
  if (c.type === 'insert' && after) {
    const cols = c.columns.filter((col) => after[col] !== undefined);
    return `INSERT INTO ${t} (${colList(cols)}) VALUES (${cols.map((col) => literal(after[col])).join(',')})`;
  }
  if (c.type === 'update' && old && after) {
    const changed = c.columns.filter((col) => old[col] !== after[col]);
    const pks = (c.primaryKeys ?? []).filter((p) => old[p] !== undefined);
    const setFields = changed.map((col) => `\`${col}\`=${literal(after[col])}`).join(',');
    let whereCond: string;
    if (pks.length > 0) {
      whereCond = pks.map((col) => `\`${col}\`=${literal(after[col])}`).join(' AND ');
    } else {
      whereCond = c.columns.filter((col) => old[col] === after[col] && old[col] !== undefined)
        .map((col) => `\`${col}\`=${literal(after[col])}`)
        .join(' AND ');
    }
    return `UPDATE ${t} SET ${setFields}\nWHERE ${whereCond}`;
  }
  if (c.type === 'delete' && old) {
    const cols = c.columns.filter((col) => old[col] !== undefined);
    const pks = (c.primaryKeys ?? []).filter((p) => old[p] !== undefined);
    const whereCols = pks.length > 0 ? pks : cols;
    return `DELETE FROM ${t}\nWHERE ${whereCols.map((col) => old[col] === null ? `\`${col}\` IS NULL` : `\`${col}\`=${literal(old[col])}`).join(' AND ')}`;
  }
  return '';
}

function rollbackStatement(c: Change): string {
  const t = fullTable(c.schema, c.table);
  if (c.type === 'insert' && c.newValues) {
    const after = c.newValues;
    // 回滚 INSERT：DELETE 定位优先主键（after 值），无主键时退回全列
    const pks = (c.primaryKeys ?? []).filter((p) => after[p] !== undefined);
    const whereCols = pks.length > 0 ? pks : c.columns;
    return `DELETE FROM ${t}\nWHERE ${whereClause(after, whereCols)};`;
  }
  if (c.type === 'update') {
    return updateRollback(c);
  }
  if (c.type === 'delete' && c.oldValues) {
    const old = c.oldValues;
    return `INSERT INTO ${t} (${colList(c.columns)})\nVALUES (${c.columns.map((col) => literal(old[col])).join(',')});`;
  }
  return '-- 缺少回滚所需的 before/after 镜像';
}

/** 单条变更的注释块：原始定位 + 源 DML + 执行时间 */
function rollbackComment(c: Change, xid: number): string[] {
  const t = fullTable(c.schema, c.table);
  const lines: string[] = [
    `-- changeId=${c.changeId} ${c.type.toUpperCase()} ${t} @ ${c.binlogFile}:${c.binlogPos} ; xid=${xid}`,
  ];
  const src = sourceDml(c);
  if (src !== '') {
    lines.push(`-- 源操作: ${src.replace(/\n/g, '\n--        ')}`);
  }
  lines.push(`-- 执行时间: ${new Date((c.timestamp > 1e11 ? c.timestamp : c.timestamp * 1000)).toLocaleString('zh-CN', { hour12: false })}`);
  return lines;
}

export function generateRollback(changes: Change[]): RollbackOutput {
  if (changes.length === 0) {
    return { ok: false, sql: '', stats: { statements: 0, transactions: 0 }, error: '未生成回滚脚本：当前没有选中的变更。请返回变更列表页勾选需要回滚的记录。' };
  }

  // 按 xid 分组
  const groups = new Map<number, Change[]>();
  changes.forEach((c) => {
    const arr = groups.get(c.xid) ?? [];
    arr.push(c);
    groups.set(c.xid, arr);
  });

  // 事务按提交时刻（组内最大 timestamp）降序
  const ordered = [...groups.entries()].sort((a, b) => {
    const ta = Math.max(...a[1].map((c) => c.timestamp));
    const tb = Math.max(...b[1].map((c) => c.timestamp));
    return tb - ta;
  });

  const header = `-- 回滚脚本 binlog-parser v1.0；生成时间 ${new Date().toLocaleString('zh-CN', { hour12: false })}`;
  const blocks: string[] = [header];
  let statements = 0;

  ordered.forEach(([xid, group]) => {
    const lines: string[] = ['START TRANSACTION;'];
    group.forEach((c) => {
      lines.push(...rollbackComment(c, xid));
      lines.push(rollbackStatement(c));
      statements += 1;
    });
    lines.push('COMMIT;');
    blocks.push(lines.join('\n'));
  });

  return {
    ok: true,
    sql: blocks.join('\n\n'),
    stats: { statements, transactions: ordered.length },
  };
}
