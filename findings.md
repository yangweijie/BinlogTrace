# Findings — Phase 3 浏览器内 MySQL 数据追踪工具

## 真实 binlog 解析：krowinski/php-mysql-replication（agent-workerman）
- 库版本 `^11.1`，支持 MySQL 8.0 + caching_sha2；依赖 doctrine/dbal + pdo_mysql（均已装）。
- **纯 PHP、同步阻塞**（`Socket::recv` + `MSG_WAITALL`，`consume()` 是死循环），
  `runWithStopCheck` 的 stop 回调只在每次 `consume()` 完成后检查 → dump 追平文件尾后
  `getResponse()` 永久阻塞，stop 回调轮不到。⇒ 必须跑独立子进程，不能进 Workerman 事件循环。
- 探针已验证：`test.order` UPDATE 全解对 —— `total_amount` NEWDECIMAL(10,2) → `"99.99"`/`"100.00"`
  （保留两位）、`created_at`/`updated_at` DATETIME2 → `"2026-02-13 03:45:08"`，before/after 完整。
- **API 关键点**：
  - `ConfigBuilder::withBinLogPosition(string)` 要字符串（传 int 报 TypeError）。
  - `RowsDTO`（WriteRowsDTO/UpdateRowsDTO/DeleteRowsDTO）的 `eventInfo` 是 protected，用
    `$event->getEventInfo()`；`values` 是 `array[]`，每行 `['before'=>..., 'after'=>...]`
    （insert 行无前缀直接是列映射）。
  - `ConstEventType::XID_EVENT` 等枚举取 `->value`。
  - **心跳事件 `HeartbeatDTO::getEventInfo()->timestamp` 恒为 0**（库实现如此），
    不能用它判断「服务器当前时间」→ 心跳兜底退出要用本地 `time()`。
  - **dump 文件头时服务端先发一个 ROTATE 定位事件**（指向被 dump 的文件），
    探测首行事件 ts 时 `onRotate` 会抢在行事件前触发 → ROTATE 不能参与判定。

## 起点 binlog 文件定位算法（worker 内）
```
PDO SHOW BINARY LOGS（按名升序 binlog.000040 < ... < 000042，末个=当前文件）
  → 反转从新到旧，逐文件 krowinski dump pos 4 读首行事件 ts
     ├─ ts < startTs → 该文件为起点（dump pos 4，--start-ts 过滤窗口前行，无遗漏）
     └─ ts ≥ startTs → 取更旧文件
  → 全部 ≥ startTs → 用最旧文件（起点早于全部可用 binlog，无遗漏）
```
- 不做文件内二分：多读的部分被 `--start-ts` 过滤，无正确性影响，仅历史很旧时探测稍慢。
- 心跳周期降到 5s（历史窗口越过 endTs 后无新行事件时靠心跳 `time() > endTs+5` 快速退出）。

## Windows 坑（agent-workerman，proc_open 子进程）
1. **env 必须 `array_merge(getenv(), [...])`**：只传 `$_ENV`（CLI 下常为空）会整体替换子进程
   环境、丢 PATH/SYSTEMROOT → `socket_create` 10106（Winsock 未初始化），krowinski 无法建连。
2. **管道 `stream_set_blocking(false)` 在 Windows 上是 no-op**：`fread` 会阻塞到子进程有输出，
   长驻 dump（追平文件尾后无输出）会卡死 Workerman 事件循环，表现为 dump 期间 query/heartbeat
   全无响应。⇒ 子进程 stdout/stderr 重定向到 `runtime/krowinski_*.out/.err` 文件，`Timer`(0.1s)
   增量轮询文件（记录已读 offset）。

## 组帧修复（agent AsyncClient，已完成）
- MySQL 每个 binlog 事件包 payload **前置 1 字节 0x00**（8.0.36 实测恒有），真实事件在偏移 1。
  未剥离时 eventType 解成 106（非法，官方 0–42）→ 前端只留 19/30/31/32/16，全被过滤 → 无输出。
- 剥离守卫：仅当偏移 1 解出合法头（type 1..42 且 eventSize==实收-1）才剥，防误伤时间戳低字节恰为 0x00 的事件。
- CRC32 尾部：`binlogChecksummed && eventSize==实收` 时剥 4 字节并同步减 eventSize；
  1236 用 `SET @master_binlog_checksum = @@global.binlog_checksum` 声明兼容。
- ROTATE 文件名在偏移 27（头 19 + pos 8），此前按 23 读会带 CRC 尾部 4 字节 → 非法 UTF-8 →
  `json_encode` 返回 false → `sendFrame` 静默丢弃（「无输出」另一根因）。
- 合法事件类型：ROTATE(4)/FDE(15)/QUERY(2)/GTID(34,35)/TABLE_MAP(19)/WRITE(30)/UPDATE(31)/DELETE(32)/XID(16)。

## 前端契约
- 真实模式消费 `binlog-change` 帧 `{kind, schema, table, columns, before, after, xid, timestamp, binlogFile, binlogPos}`，
  `useTraceRun.finalizeStructured` 映射成 `Change`（值统一 string|null），**不调 WASM parse-binlog**（demo 才走 WASM）。
- `passedEnd`/窗口过滤的 `toMs`：`t > 1e11 ? t : t*1000`（agent 事件 ts 是 epoch 秒，endMs 是毫秒；
  1e11=1973 年为阈值）。
- worker 正常退出 → `binlog-end` 帧 → `session.isEnded` finalize；不等 45s 空闲。

## 结果页筛选栏单行对齐（2026-08-23）
- `frontend/src/pages/ResultPage.tsx` 的 `.filter-bar` 用了 `display:flex; flex-wrap:nowrap; align-items:center`，
  但子元素高度差 28px+（`filter-pills` 26px 高 vs `Select` 56px 高），视觉上下拉被顶到胶囊下方。
- 根因在 `Select.tsx`：始终渲染 `<div class="field">`（`display:flex; flex-direction:column; gap:4px;
  margin-bottom:16px`），即使无错误也含一个 `<div class="field-error" role="alert">`（`min-height:16px`）。
- **不改组件**：在 `frontend/src/styles/components.css` 已有 `.filter-bar .select-sm` 规则附近加：
  `.filter-bar .field { min-width:0; margin-bottom:0; }` + `.filter-bar .field-error { display:none; }`
  并用注释标明是有意覆盖（防御未来"清理无意义规则"误删）。
- 验证：`evaluate_script` 测得四子元素 centerY 全部为 123（修复前 131/123 两种），`.filter-bar` 高从 72px 降到 26px。
- `frontend/src/pages/ConnectPage.tsx` `runSaved()`：`useDemo: false`（已修复）——已存真实连接点击后强制真实代理，不再残留演示模式状态

## 本轮发现（MySQL 认证 + 前端状态清理）
- `AsyncClient` 的 `mysql_native_password` 认证在 `MySQL 5.7.43` + `mysql_native_password` 插件下仍然 `1045`（`krowinski` 同服务器认证通过 → 证明不是服务器限制，而是 `AsyncClient` 协议实现缺陷）
- `agent-workerman` 的真实 `binlog-dump` 本就不依赖 `mysql` 主连接（走 `krowinski_dump.php` 子进程 + `proc_open`），因此可安全将 `mysql` 主连接也替换为 `KrowinskiQueryAdapter`
- `adapter` 连接成功后 `MetaGatherer` 执行 `SHOW MASTER STATUS` 正常返回（`File` + `Position`），`serverVersion` 正常（`5.7.43-log`），`userPrivileges` 正常（`SELECT` + `REPLICATION SLAVE` + `REPLICATION CLIENT`）
- 前端 `undefined` 下拉：源自 `adapter` `columns` 一直为空数组（`fetchAllAssociative()` 结果没有被正确解析为列名）→ 改 `PDO::FETCH_NUM` + `getColumnMeta()` 提取列名后修复
- 前端 `runSaved()` 保留 `useDemo` 状态：点击已保存真实连接时仍走演示路径，`checkResult` 不清空，`开始追踪` 按钮被 `blocking` 禁用 → 改 `useDemo: false` 后修复

## 已存连接同名覆盖（2026-08-23）
- `ConnectPage.doConnect` 每次 `newId()` → `upsertConnection` 以 id 为键 → `findIndex` 永远 -1 → 永远 `unshift`，
  同名连接反复堆积成多条。
- 修复：保存前先按 `name` 在 `saved` 里查找已存条目的 `id`，找到就复用；未找到才 `newId()`。
  `upsertConnection` 即可正确命中并覆盖。
- 验证：脚本模拟 3 次保存（同名 2 次 + 异名 1 次）→ 最终 count=2、uniqueIds=2，覆盖行为正确。
- 修改：`frontend/src/pages/ConnectPage.tsx`（`doConnect` 内新增 `connName`/`existingId` 两行 + 注释）。

## 本机环境
- MySQL 8.0.36：`log_bin=1`、`binlog_format=ROW`、`row_image=FULL`、`binlog_checksum=CRC32`、
  `binlog_row_metadata=MINIMAL`（krowinski 回退 information_schema 取列名）。
- 用户实际改的是 `test.order`；Vite dev 5173；Node v24.10.0；tpc 在
  `/d/git/php/tpc_v1113_windows_x86_64/tpc`（PE32+ x86-64, php 8.4）——有依赖障碍，故未走 WASM 重建。

## 原生 agent/ 路径（2026-08-24，TypePHP 编译目标）

### AgentHandler::handleDump 契约（agent/src/AgentHandler.php:168-314）
- spawn 脚本 `__DIR__.'/../bin/mysqlbinlog_dump.php`；CLI：`--host --port --user --file <binlogFile> --pos <pos>` + 条件 `--start-ts <sec>`(startMs/1000>0) + `--end-ts <sec>`(endMs/1000>0)。密码经 ENV `DMS_MYSQL_PASSWORD`；cwd=`agent/`；stdout(1)/stderr(2) pipe w。
- stdout 逐行 JSON：`onDumpReadable` 仅转发 `type==='change'` 行 → `binlog-change` 帧（字段 kind,schema,table,columns,primaryKeys,before,after,xid,timestamp,binlogFile,binlogPos）；其他 type（如 heartbeat）忽略。
- exit 0 → `binlog-end{exitCode:0}`；非 0 → error code INTERNAL_ERROR「binlog 解析进程异常退出 code=N」+ stderr 尾部 300 字。
- `dump-started` 帧显示的是 AgentHandler 在 spawn 前从 MetaGatherer 取的**当前尾巴位置**（mysql-bin.000005/1365），**非**实际起点；实际起点由 worker 内部 `probeStartFile` 决定，看 `[dump-err]` 日志。

### mysqlbinlog CLI 在本机不可行
- 本机 `mysqlbinlog`（MySQL 9.4.0）已移除 `mysql_native_password` 客户端插件 → 连远程 5.7 报 `Authentication plugin 'mysql_native_password' cannot be loaded: dlopen(.../mysql_native_password.so... no such file)` → worker code=1、AgentHandler 报 code 1099。
- 故将 `agent/bin/mysqlbinlog_dump.php` 整体改为纯 PHP `krowinski/php-mysql-replication`（与 agent-workerman 同源），不再依赖任何本地二进制，TypePHP 打包后无外部依赖。

### krowinski 在 agent/vendor 已可直接 autoload
- `php -r "require 'vendor/autoload.php'; var_dump(class_exists('MySQLReplication\\MySQLReplicationFactory'));"` → `bool(true)`；`agent/composer.json` require 仅 php/ext-pdo*/ext-sockets，但 vendor 内已装且 autoload 含 krowinski，**无需改 composer.json**。
- 目标 MySQL 5.7 用 mysql_native_password，krowinski 自实现 socket 支持；仅 MySQL 8.4+/9（仅 caching_sha2）曾握手失败（Got packets out of order），与本目标无关。

### 历史窗起点定位（worker 内，复刻 agent-workerman）
- `SHOW BINARY LOGS`（PDO）→ `array_reverse` 从新到旧 → 逐文件 krowinski 从 pos 4 取首个行事件 ts → 首个 `ts < startTs` 的文件为起点（pos 4 起，窗口前行被 start-ts 过滤，无遗漏）；全部文件首事件 ≥ startTs → 回退最旧文件。
- 主 `MySQLReplicationFactory` 经 ROTATE 续读后续文件；`onXID` 缓冲赋 xid 并作时间窗过滤与越界 `exit(0)`（加 `timestamp>0` 守卫防 ts=0 误杀）；`onHeartbeat` 用本地墙钟 `time()>$endTs+5` 兜底退出。
