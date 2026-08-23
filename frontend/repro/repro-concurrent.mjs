// repro-concurrent.mjs — 复现 StrictMode 双挂载场景：connect 后并发两帧同 SQL 查询
// 期望：两帧都 settle 且 rows 一致；若第二帧 rejected(1006) 即复现「下拉被 error 禁用」
globalThis.window = globalThis;
const { createSession } = await import('./session.mjs');

const t0 = Date.now();
const log = (m) => console.log(`[${((Date.now() - t0) / 1000).toFixed(2)}s] ${m}`);

const session = createSession(false);
const meta = await session.agent.connect({
  host: '127.0.0.1', port: 3306, user: 'root', password: 'root', database: '',
});
if (!meta) process.exit(4);
log('connected: version=' + meta.serverVersion);

const SQL = 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA';
const results = await Promise.allSettled([
  session.query(SQL),
  session.query(SQL),
]);

let fail = 0;
results.forEach((r, i) => {
  if (r.status === 'fulfilled') {
    log(`query#${i + 1} OK rows=` + (r.value?.rows?.length ?? 0));
  } else {
    fail++;
    log(`query#${i + 1} REJECTED: ` + r.reason?.message);
  }
});

if (fail > 0) {
  console.log('REPRO RESULT: CONCURRENT FAIL — ' + fail + '/2 rejected（下拉将被 error 禁用）');
  process.exit(2);
}
console.log('REPRO RESULT: CONCURRENT OK — 2/2 settled');
process.exit(0);
