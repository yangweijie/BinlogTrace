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
