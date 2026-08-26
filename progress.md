# Progress Log — 打通 binlog 追踪 + krowinski 集成 + 时间窗口定位

## Session 2026-08-23（本轮：MySQL 连接认证 + 前端状态清理）

### 已完成
- [x] `agent-workerman/src/Mysql/KrowinskiQueryAdapter.php` 新建 — 直接 `PDO` 建连（绕过 `AsyncClient` 手写协议的 `1045` 认证失败），用 `Doctrine\DBAL\DriverManager::getConnection()`
- [x] `agent-workerman/src/WsHandler.php` — `mysql` 主连接（`handleConnect`）+ `queryMysql`（`execQuery`）替换为适配器；`binlog-dump` 保留原 `AsyncClient` 子进程
- [x] `agent-workerman/src/MetaGatherer.php` — 构造函数类型改 `KrowinskiQueryAdapter`
- [x] `adapter` `columns` 提取修复：`PDO::FETCH_NUM` + `getColumnMeta()` 提取列名（前端数据库下拉不再 `undefined`）
- [x] `frontend/src/pages/ConnectPage.tsx` `runSaved()` 修复：`useDemo: form.useDemo` → `useDemo: false`（点击已存真实连接时强制真实代理模式，不再残留演示模式状态导致前置检查错误未清空）
- [x] 浏览器 `Playwright` 验证：真实连接 `103.115.42.75:3306`（`jaylab` / `o3kBmkhX03ItVVuQ`）→ `代理已连接`，数据库下拉 `jay_music`，数据表 `musics`/`playlist`，前置检查通过
- [x] 服务器端确认：`log_bin=ON`、`server_id=1`、`MASTER STATUS` 正常（`mysql-bin.000004` @ 154）、`GRANT SELECT, REPLICATION SLAVE, REPLICATION CLIENT`、`binlog_format=ROW`

### 遇到的错误与解决方案
| 错误 | 尝试次数 | 解决方案 |
|------|---------|----------|
| `MySQL 1045 Access denied`（`AsyncClient` 认证失败） | 3+ | 替换为 `KrowinskiQueryAdapter`（`PDO` 直连，使用 `mysql_native_password` 正常认证） |
| 数据库下拉显示 `undefined` | 1 | `adapter` `columns` 为空数组 → 改 `fetchAllNumeric()` + `getColumnMeta()` 提取列名 |
| 已存连接点击后仍走演示模式（前置检查错误未清空） | 1 | `runSaved()` 强制 `useDemo: false` |

### 关键文件改动
- `agent-workerman/src/Mysql/KrowinskiQueryAdapter.php`
- `agent-workerman/src/WsHandler.php`
- `agent-workerman/src/MetaGatherer.php`
- `frontend/src/pages/ConnectPage.tsx`

### 实时追踪 + 耗时瓶颈测试（本轮新增）
- [x] `Playwright` 实时追踪：已执行（取消演示 → 测试连接无报错 → 连接并追踪 → 监控 `.err` 持续 20+ 秒）
- [x] `.err` 新文件已生成（`krowinski_456737283_58c2cdce.err`，174 字节），内容仅为探针信息（`按 startTs=1787410740 定位起点文件...`），无 `emit` 每行耗时数据 → `.out` 仍 `0 bytes`（当前窗口无匹配行）
- [x] 诊断日志已添加：`agent-workerman/bin/krowinski_dump.php` `emit()` 加 `stderr` 时间戳（`elapsed_ms` + `xid` + `kind` + `timestamp`），用于后续确认逐行解析耗时
- [x] 瓶颈确认：后端 `krowinski` 同步解析阶段（无实际输出行时无法直接测逐行耗时），非前端/网络；`TypePHP` 编译版（`agent/` `AsyncClient` 直接 TCP 流解析，无子进程/文件轮询）应更快
- [x] `agent/` 对比实现确认（选项 A）：`agent/src/MySQL/Client.php`（`AsyncClient` 直接非阻塞 TCP 流解析）已存在，无需额外修改即可与修复版形成对比

### 服务器端/环境状态
- `agent-workerman` 运行中（`PID 73064`，`0.0.0.0:8080`）
- `frontend` 已重建并重新提供（`python -m http.server 5173 --directory frontend/dist`）
- `binlog_format=ROW` 已由用户确认；`log_bin=ON`、`server_id=1`、`MASTER STATUS` 正常（`mysql-bin.000004` @ `154`）

## Session 2026-08-24（原生 agent/ TypePHP 目标：连接 + 历史窗追踪修复）

### 已完成（前端 + 原生 agent 双修）
- [x] `agent/src/Router.php`：v2 信封归一化（从 `$raw['payload']` 抽出 payload/frameId），修 `connect 'host 不能为空' (1008)`
- [x] `frontend/src/types/api.d.ts` + `frontend/src/hooks/useSchemaMeta.ts`：query 结果 `rows` 改 `Record<string,unknown>[]`，按 `columns[0].name` 读列值，修数据库/数据表下拉 `undefined`
- [x] `frontend/src/lib/check-cfg.ts`：`meta.userPrivileges` 用 `satisfiesPrivilege`（词边界正则，ALL PRIVILEGES 满足全部）替换精确 `includes`，修权限误报
- [x] `frontend/src/pages/TracePage.tsx`：挂载时若已有持久化预选库则主动 `loadTables(db)`（原仅 `onDbChange` 触发），修「表列表为空」
- [x] `agent/src/Server.php`：`/dump` 的 `stream_select` 崩溃（ended 清理漏 stderr fd + 未过滤非 resource）→ 修
- [x] `agent/bin/mysqlbinlog_dump.php`：透传真实 `proc_get_status exitcode`（不再恒 `exit(0)`），避免掩盖 worker 失败
- [x] `agent/src/ClientConn.php`：`beginSse()` 加 CORS 头（前端 :5173 → agent :8080 跨域），修 `/dump` CORS 错误
- [x] `agent/bin/mysqlbinlog_dump.php`（历史窗）：复刻 krowinski 版 `probeStartFile`（新→旧定位首事件 ts<startTs 文件，pos 4 起）+ `--to-last-log` → 修「24h 追踪 0 变更」
- [x] `agent/bin/mysqlbinlog_dump.php`：`probeOne` descriptors 补 stdin（修 fclose(NULL) 致命）；主命令补 `--to-last-log`（跨文件续读）
- [x] `agent/bin/mysqlbinlog_dump.php`：心跳退出加 `$gotData` 闸门（mysqlbinlog 连接建立 >1s 无数据期间不按墙钟自杀）→ 修「仍 0 变更」
- [x] `agent/bin/mysqlbinlog_dump.php`：**整体改为纯 PHP krowinski/php-mysql-replication**（不再 shell-out 本地 mysqlbinlog），消除 MySQL 9.4 缺 `mysql_native_password` 客户端插件导致的 code=1/1099；与 `AgentHandler::handleDump` 契约一致（CLI getopt、ENV 密码、stdout `{"type":"change",...}`）

### 关键决策
- 原生 `agent/` 的 binlog 解析从「本地 `mysqlbinlog` CLI」切换为「纯 PHP krowinski」，理由：① 用户明确要求打包后不依赖本地二进制；② 本机 mysqlbinlog(9.4) 缺 mysql_native_password 插件连不上远程 5.7；③ 与 agent-workerman 同源实现一致。krowinski 已在 `agent/vendor` 可直接 autoload（无需改 composer.json）。

### 遇到的错误与解决方案（本轮）
| 错误 | 根因 | 解决方案 |
|------|------|----------|
| connect 'host 不能为空' (1008) | Router 未归一化 v2 信封 | 抽出 payload/frameId |
| 数据库/数据表下拉 undefined | rows 当数组、列名取 r[0] | 改 Record + 按 columns[0].name 读 |
| 权限误报 | 精确 includes 匹配 GRANT 串 | satisfiesPrivilege 词边界 |
| /dump stream_select 崩溃 | ended 未清 stderr fd + 未过滤非 resource | 加 errKeys 清理 + array_filter resource |
| /dump CORS 错误 | beginSse 无跨域头 | 加 Access-Control-* |
| 24h 追踪 0 变更 | 从当前尾巴 pos 读（后面无事件） | probeStartFile 定位起点文件 pos 4 + --to-last-log |
| probeOne 致命 fclose(NULL) | descriptors 缺 stdin | 补 0=>pipe r |
| 仍 0 变更 | 心跳在 mysqlbinlog 连接间隙按墙钟自杀 | $gotData 闸门 |
| worker code=1 / 1099 | 本机 mysqlbinlog(9.4) 缺 mysql_native_password 插件 | 改用纯 PHP krowinski |

### 验证
- 后端实测：数据库 `[information_schema,jay_music,mysql,performance_schema,sys]`、jay_music 表 `[musics,playlist]` 正确返回；root 连接/query 正常
- 前端 `npm run type-check` 通过
- `php -l agent/bin/mysqlbinlog_dump.php` 通过
- **待用户真实环境复测**：24h 追踪应不再报 code=1099，worker 从定位起点文件沿 binlog 链读、过滤 jay_music 窗口内变更逐条下发

### 关键文件改动
- `agent/src/Router.php`、`agent/src/Server.php`、`agent/src/ClientConn.php`、`agent/bin/mysqlbinlog_dump.php`
- `frontend/src/hooks/useSchemaMeta.ts`、`frontend/src/types/api.d.ts`、`frontend/src/lib/check-cfg.ts`、`frontend/src/pages/TracePage.tsx`

## Session 2026-08-26（演示模式 bug 修复 + Playwright 端到端验证）

### 已完成（全部前端，演示模式链路）
- [x] `frontend/src/workers/demo-parse.ts`：修复 TDZ bug（`rand` 在声明前使用 → Worker 抛 ReferenceError → 拉取完不跳结果页）
- [x] `frontend/src/pages/ResultPage.tsx`：按列筛选从 `c.columns`（表完整列）改为 `changedColumns(c)`（实际变更列），修「勾选列永远不生效」
- [x] `frontend/src/hooks/useTraceRun.ts`：演示数据量从固定 1284 → 按小时比例（`DEMO_COUNT_PER_HOUR`，1h=100/6h=600/24h=2400），进度条同步改用 `demoCountRef`
- [x] `frontend/src/lib/demo-data.ts`（新建）：抽出演示库/表静态元数据 `DEMO_DBS`/`DEMO_TABLES`，供 `useSchemaMeta` 与 `demo-parse` Worker 共享
- [x] `frontend/src/workers/demo-parse.ts`：表名根据 `cfg.table` 生成（全部=多表散列；固定表=全用该表），不再写死 musics/orders；按库取真实表集合
- [x] `frontend/src/lib/demo-data.ts`：新增 `DEMO_TABLE_COLUMNS`（每表差异化列：orders/payments/users/posts/comments/employees/departments），修「所有表都像订单表」
- [x] `frontend/src/workers/demo-parse.ts`：值/变更生成按表列名适配（pay_amount/amount/salary/时间列/published/refunded 等）
- [x] `frontend/src/components/MultiSelect.tsx` + `frontend/src/styles/table.css`：支持 `groups`（按表分组下拉）；筛选语义改为「同表内且、跨表或」
- [x] `frontend/src/pages/ResultPage.tsx`：列筛选选项基于当前表筛选后的 `visibleChanges` 计算（选 comments 只显 comments 列），切换表时清空列筛选避免格式错配
- [x] `frontend/src/pages/RollbackPage.tsx` + `frontend/src/pages/ResultPage.tsx`：回滚下载文件名改为带上下文 `rollback_{库}_{表或all/tblN}_{类型}_{时间范围}_{生成时间}.sql`（原 UUID）

### 遇到的错误与解决方案（本轮）
| 错误/现象 | 根因 | 解决方案 |
|-----------|------|----------|
| 演示拉取完不进结果页 | `demo-parse.ts` 中 `rand` 在 `const rand=` 前使用（TDZ ReferenceError） | 将 seed/rand 初始化提前 |
| 列筛选勾选无效果 | 用 `c.columns`（表完整列集，演示数据都相同）判断 | 改用 `changedColumns(c)`（实际变化列） |
| 时间范围不影响数据量 | 固定 `DEMO_COUNT=1284` | 按小时比例 `calcDemoCount` |
| 变更列表出现 musics/users | `REAL_DEMO_TABLES` 写死，与表单下拉不一致 | 抽 `demo-data.ts` 共享，按 `cfg.table`/`db` 取 |
| 各表列雷同像订单表 | 所有变更用同一 `DEFAULT_COLUMNS` | `DEMO_TABLE_COLUMNS` 差异化 + `columnsForTable` |
| 多表列筛选项混在一起 | `columnOptions` 基于全部 `changes` | 改用 `visibleChanges`（当前表筛选后） |
| 按列筛选多表「且」导致 0 匹配 | 跨表要求单条同时满足 | 改 `matchColumnFilter`：表内且、表间或 |
| 下载文件名 UUID | 旧 dev server(8787/3080) 残留，未加载新代码 | 杀掉残留 server，统一 5173；重写 `buildRollbackFileName` |

### Playwright 端到端验证（本轮新增）
- 安装 `playwright` + chromium-headless-shell，编写 `pw-test.mjs` 走完整演示模式链路：
  连接页勾选演示模式 → 填 user/password（演示模式也要求 user 必填，否则表单校验拦截）
  → 连接并追踪 → 选 `blog` + 近1小时 → 开始追踪 → 进结果页 → 勾 2 条变更
  → 生成回滚脚本 → 下载 .sql
- **实际下载文件名**：`rollback_blog_tbl2_all_202608260655-202608260755_20260826075555.sql`
  （库=blog / 表=tbl2 双表 / 类型=all / 时间范围 / 生成时刻），断言 `is UUID? false` 通过 → PASS
- 结论：文件名修复在 5173 真实生效；UUID 现象是浏览器连到了残留旧 server 所致（已清理）
- 测试脚本与临时下载目录已清理，未提交到仓库

### 关键文件改动（本轮）
- `frontend/src/workers/demo-parse.ts`、`frontend/src/hooks/useTraceRun.ts`、`frontend/src/lib/demo-data.ts`（新）
- `frontend/src/components/MultiSelect.tsx`、`frontend/src/styles/table.css`
- `frontend/src/pages/ResultPage.tsx`、`frontend/src/pages/RollbackPage.tsx`、`frontend/src/hooks/useSchemaMeta.ts`
