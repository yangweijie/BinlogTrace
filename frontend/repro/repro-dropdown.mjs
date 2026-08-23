// repro-dropdown.mjs — 用真实前端代码（session.ts/ws.ts，esbuild 打包）复现「库列表下拉」链路
// 用法: node repro/repro-dropdown.mjs
// Node 环境 shim：ws.ts 使用 window.setTimeout / WebSocket 等浏览器全局
globalThis.window = globalThis;
const { createSession } = await import('./session.mjs');

const t0 = Date.now();
const log = (m) => console.log(`[${((Date.now() - t0) / 1000).toFixed(2)}s] ${m}`);

const session = createSession(false);

// 与真实流程一致：先 connect（ConnectPage），再 query（TracePage 挂载时）
const meta = await session.agent.connect({
  host: '127.0.0.1', port: 3306, user: 'root', password: 'root', database: '',
}).catch((e) => { log('connect rejected: ' + e.message); return null; });
if (!meta) process.exit(4);
log('connected: version=' + meta.serverVersion + ' user=' + meta.user);

// 追踪页加载库列表的完整链路：session.query（等价 useSchemaMeta.loadDatabases）
const p = session.query('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA');

// 5s 看门狗：若 Promise 永不 settle，loading 将永远为 true → 下拉 disabled
const dog = setTimeout(() => {
  console.error('REPRO RESULT: PROMISE NEVER SETTLED (5s) — 即用户看到的「下拉无法选择」（loading 卡死）');
  process.exit(2);
}, 5000);

try {
  const res = await p;
  clearTimeout(dog);
  log('query settled, type=' + Object.prototype.toString.call(res));
  console.log('RES keys:', Object.keys(res ?? {}));
  console.log('rows type:', Object.prototype.toString.call(res?.rows), 'len=' + (res?.rows?.length ?? 0));
  const dbs = (res?.rows ?? []).map((r) => String(r?.[0])).filter((n) => !['information_schema', 'performance_schema', 'mysql', 'sys'].includes(n));
  log('前端过滤后的 dbOptions: ' + JSON.stringify(dbs));
  console.log('REPRO RESULT: OK — 数据管道通畅，若页面仍不可用则是页面状态/旧 JS 问题');
  process.exit(0);
} catch (e) {
  clearTimeout(dog);
  log('query rejected: ' + e.message);
  console.log('REPRO RESULT: REJECTED — ' + e.message);
  process.exit(3);
}
