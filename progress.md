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

## Session 2026-08-25（updatedWithin 误杀修复 + vendor-c 清理）

### 已完成
- [x] **`updatedWithin` 默认强制开启导致「查询范围查不到变更 + 一直心跳不停止」修复**
  - 根因：`WsHandler.php` 中 `updatedWithin` 默认 86400 且按窗口长度推算，子进程对每行执行 `updated_at` 业务时间过滤，
    与「按 binlog 事件时间窗口（startMs/endMs）查询」叠加，把 `updated_at` 不在最近 24h 内的行 `continue` 误杀 → 查不到变更。
  - `agent-workerman/src/WsHandler.php`：去掉默认 86400 与按窗口长度推算，仅当 `payload['updatedWithin']>0` 显式传入才启用（默认 0 = 关闭）。
  - `agent-workerman/bin/krowinski_dump.php`：`--updated-within` 默认值 `86400` → `0`（与 WsHandler 一致）；`onXID` 内 `if ($st->updatedWithin > 0)` 过滤逻辑不变。
  - 现在「查询范围」回到纯 binlog 事件时间（startTs/endTs）过滤，与「之前能查到变更」行为一致；越界后 `runWithStopCheck` 自动退出 → `binlog-end` → SSE 关闭。
  - `updatedWithin` 保留为可选显式开关（前端传 `updatedWithin:86400` 即恢复「只捕获 updated_at 最近24h」语义），与范围查询正交。
- [x] **`agent/vendor-c` 无用文件清理**
  - 删除 `mariadb-connector-c-3.1.28-src/`（283 文件，未被 `project.yml` 引用的误下载 MariaDB 源码树）。
  - 删除顶层冗余副本 `vendor-c/bin/`、`vendor-c/include/`、`vendor-c/lib/`（均为 `mysql-connector-c-6.1.11-winx64/` 的重复，且全仓库 0 脚本引用顶层路径）。
  - 保留 `mysql-connector-c-6.1.11-winx64/{include,lib,bin,docs}` + `COPYING`/`README`（`project.yml` 实际引用）。
  - 验证：子目录 `lib/libmysql.dll`(4,879,360) = 原顶层 `bin/libmysql.dll`；运行时 dll 实际在 `agent/libmysql.dll`（已复制到 agent 根，exe 同目录），不依赖 vendor-c 下 dll。

### 关键决策
- `updatedWithin` 不应默认全局强制开启，必须与「按时间范围查询」解耦，否则叠加误杀变更。
- `vendor-c` 顶层 `bin/include/lib` 是历史遗留的冗余副本，非构建所需（构建只引用 `mysql-connector-c-6.1.11-winx64/`）。

### 关键文件改动
- `agent-workerman/src/WsHandler.php`（`handleBinlogDump` 内 updatedWithin 计算）
- `agent-workerman/bin/krowinski_dump.php`（`--updated-within` 默认值）
- `agent/vendor-c/`（删除 mariadb 源码树 + 顶层冗余副本）
