// sql-highlight.ts — 轻量 SQL 词法高亮（Token 映射，仅用锁定 Token，禁止新增颜色）

export interface SqlToken {
  text: string;
  cls: string;
}

const MAIN_VERBS = new Set(['DELETE', 'INSERT', 'UPDATE']);
const KEYWORDS = new Set([
  'FROM', 'WHERE', 'SET', 'VALUES', 'INTO', 'AND', 'OR',
  'START', 'TRANSACTION', 'COMMIT', 'IS', 'NULL',
]);

function mainCls(verb: string): string {
  if (verb === 'DELETE') return 'tok-main-delete';
  if (verb === 'INSERT') return 'tok-main-insert';
  return 'tok-main-update';
}

function isDigit(ch: string): boolean {
  return ch >= '0' && ch <= '9';
}

function isWordChar(ch: string): boolean {
  return /[A-Za-z0-9_`$]/.test(ch);
}

/** 单行 tokenize（按设计提示词 §页面5 高亮表） */
export function tokenizeLine(line: string): SqlToken[] {
  const tokens: SqlToken[] = [];
  let i = 0;
  const n = line.length;

  while (i < n) {
    const ch = line[i];

    // 行注释 --
    if (ch === '-' && line[i + 1] === '-') {
      tokens.push({ text: line.slice(i), cls: 'tok-comment' });
      break;
    }
    // 块注释 /* ... */
    if (ch === '/' && line[i + 1] === '*') {
      const end = line.indexOf('*/', i + 2);
      const slice = end === -1 ? line.slice(i) : line.slice(i, end + 2);
      tokens.push({ text: slice, cls: 'tok-comment' });
      i += slice.length;
      continue;
    }
    // 字符串字面量 '...' / "..."
    if (ch === "'" || ch === '"') {
      let j = i + 1;
      while (j < n) {
        if (line[j] === ch && line[j - 1] !== '\\') break;
        j += 1;
      }
      const slice = line.slice(i, Math.min(j + 1, n));
      tokens.push({ text: slice, cls: 'tok-str' });
      i += slice.length;
      continue;
    }
    // 数字（含小数点）
    if (isDigit(ch) || (ch === '.' && isDigit(line[i + 1] ?? ''))) {
      let j = i;
      while (j < n && (isDigit(line[j]) || line[j] === '.')) j += 1;
      tokens.push({ text: line.slice(i, j), cls: 'tok-num' });
      i = j;
      continue;
    }
    // 单词 / 标识符
    if (/[A-Za-z_`]/.test(ch)) {
      let j = i;
      while (j < n && isWordChar(line[j])) j += 1;
      const word = line.slice(i, j);
      const up = word.toUpperCase();
      if (word.startsWith('`')) {
        tokens.push({ text: word, cls: 'tok-ident' });
      } else if (up === 'NULL') {
        tokens.push({ text: word, cls: 'tok-null' });
      } else if (MAIN_VERBS.has(up)) {
        tokens.push({ text: word, cls: mainCls(up) });
      } else if (KEYWORDS.has(up)) {
        tokens.push({ text: word, cls: 'tok-kw' });
      } else {
        tokens.push({ text: word, cls: 'tok-ident' });
      }
      i = j;
      continue;
    }
    // 标点 / 空白
    tokens.push({ text: ch, cls: 'tok-plain' });
    i += 1;
  }
  return tokens;
}

/** 整段 SQL → 行数组（保留空行） */
export function splitSqlLines(sql: string): string[] {
  return sql.split('\n');
}
