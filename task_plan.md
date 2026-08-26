# Task Plan: 打通「查 binlog → 找变更 SQL → 生成回滚 SQL」链路

## Goal
让前端「选时间点拉取」能正确捕获真实 MySQL 的历史行变更，并支持按时间窗口（start/end）
定位 binlog 文件与位置、只输出窗口内变更、结束后及时 finalize。

## Context
- 本仓库是 TypePHP DMS：`parser/`(WASM) + `agent/`(native) + `agent-workerman/`(纯 PHP Workerman，
  免编译器) + `frontend/`(React 18 + Vite)。
- 真实 binlog 解析最终落在 **agent-workerman**：`krowinski/php-mysql-replication` 库（纯 PHP、
  同步阻塞）跑在独立子进程，绕过 WASM 手写解码 NEWDECIMAL/DATETIME2 的短板与 tpc 重建障碍。
- 架构边界：agent 只做「头解析 + 0x00 前缀/CRC 尾部剥离 + eventSize 修正 + 透传」，行值解码
  交给 krowinski 子进程；前端真实模式消费 `binlog-change` 帧，**不再调 WASM parse-binlog**
  （demo 仍走 WASM）。

## Phases

- [x] **Phase A — 组帧修复**（agent 侧，已完成）
  - [x] `AsyncClient::handleBinlogPacket` 剥离每事件包前置 0x00（仅当偏移 1 解出合法头
        type 1..42 且 eventSize==实收-1 才剥，防误伤时间戳低字节恰为 0x00 的事件）
  - [x] 剥离 CRC32 尾部并同步修正头部 eventSize（守卫 `binlogChecksummed && eventSize==实收`）
  - [x] ROTATE 文件名偏移 23→27（头 19 + pos 8）；此前含 CRC 尾部 → 非法 UTF-8 →
        `json_encode` false → `sendFrame` 静默丢弃整条流（「无输出」根因之一）
  - [x] 1236 用 `SET @master_binlog_checksum = @@global.binlog_checksum`（真从库声明方式）
  - [x] 探针回归：`_probe_dump_frames.php` 整文件重放 binlog.000042 @4，18 事件 17 正确

- [x] **Phase B — 前端单位修复**（已完成）
  - [x] `useTraceRun` `passedEnd` 秒/毫秒不匹配：`toMs` 以 1e11 阈值归一化后再比 `endMs`
        （否则真实模式永不结束、按钮一直禁用）

- [x] **Phase C — 集成 krowinski 解析**（agent 侧，已完成）
  - [x] `bin/krowinski_dump.php` 子进程：JSON 行输出结构化变更
        `{type:change, kind, schema, table, columns, before, after, xid, timestamp, binlogFile, binlogPos}`
  - [x] XID 缓冲赋号（行事件在其 XID 之前，须等 XID 再输出，否则 xid 错乱）
  - [x] `WsHandler::handleBinlogDump` 改为 `proc_open` 启动子进程；stdout 转发 `binlog-change` 帧
  - [x] **Windows 坑 1**：`proc_open` env 必须 `array_merge(getenv(),[...])` —— 只传 `$_ENV`
        (CLI 下空) 会丢 PATH/SYSTEMROOT → Winsock 10106，krowinski 无法建连
  - [x] **Windows 坑 2**：管道 `stream_set_blocking(false)` 在 Windows 上无效，`fread` 会阻塞
        到子进程输出，长驻 dump 会卡死事件循环（query/heartbeat 全无响应）→ 改 stdout/stderr
        重定向到 `runtime/krowinski_*.out/.err` 文件 + `Timer`(0.1s) 增量轮询文件
  - [x] 密码走环境变量 `DMS_MYSQL_PASSWORD`（不进进程列表）

- [x] **Phase D — 时间窗口 + 历史定位**（本轮重点，已完成）
  - [x] worker 加 `--start-ts/--end-ts`（epoch 秒）：早于 startTs 的行跳过不输出；
        越过 endTs 的行事件 → 正常退出；无行事件时心跳兜底 `time() > endTs+5` 退出
  - [x] worker 内定位起点 binlog 文件：PDO `SHOW BINARY LOGS` + 从新到旧逐文件 krowinski
        探测首行事件 ts；首个 `ts < startTs` 的文件为起点（dump pos 4，窗口前行被 start-ts
        过滤，无遗漏；**不做文件内二分**）
  - [x] 心跳周期 60s→5s（历史窗口越过 endTs 后无新行事件时靠心跳快速退出）
  - [x] `WsHandler` 透传 `startMs/endMs`（epoch 毫秒→秒）给 worker
  - [x] worker 正常退出（code 0）→ agent 发 `binlog-end` 帧 → 前端 `session.isEnded`
        立即 finalize（不等 45s 空闲）；异常退出 → `binlog-end` error 帧（带 stderr 尾部）
  - [x] 前端 `ws.ts` startDump 透传 startMs/endMs；`finalizeStructured` 按 `[startMs,endMs]`
        过滤变更后再落库跳转

- [x] **Phase E — 结果页 UI 细节**（本轮新增，已完成）
  - [x] 筛选栏 `.filter-bar` 单行对齐：`.field` 外层 + 空 `.field-error` 占位把 `Select` 撑高到 56px，
        局部 CSS 覆盖（不改组件）后四子元素垂直对齐
  - [x] 已存连接同名覆盖：`ConnectPage.doConnect` 按 `name` 复用已有 `id`，消除 `upsertConnection`
        永远 `unshift` 的同名堆积

- [x] **Phase F — 原生 agent/（TypePHP 编译目标）链路修复**（本轮，已完成）
  - [x] `Router` v2 信封归一化（修 connect 'host 不能为空' 1008）
  - [x] 前端 query 结果 `rows` 改 `Record<string,unknown>[]`、按 `columns[0].name` 读列值（修数据库/数据表下拉 `undefined`）
  - [x] `check-cfg` 权限改 `satisfiesPrivilege` 词边界匹配（修误报）
  - [x] `TracePage` 挂载时若已持久化预选库则主动 `loadTables(db)`（修表列表空）
  - [x] `/dump` `Server.php` 的 `stream_select` 崩溃（ended 清理漏 stderr fd + 未过滤非 resource）修复
  - [x] `mysqlbinlog_dump.php` 透传真实 `proc_get_status` exitcode（不再恒 `exit(0)` 掩盖失败）
  - [x] `ClientConn::beginSse()` 加 CORS 头（修前端 :5173 → agent :8080 跨域 /dump 错误）
  - [x] 历史窗 `probeStartFile` 起点定位（新→旧取首事件 ts<startTs 文件，pos 4 起）+ `--to-last-log`（修 24h 0 变更）
  - [x] `probeOne` descriptors 补 stdin（修 fclose(NULL) 致命）；主命令补 `--to-last-log`（跨文件续读）
  - [x] 心跳退出加 `$gotData` 闸门（mysqlbinlog 连接间隙 >1s 无数据不按墙钟自杀）
  - [x] **binlog 解析整体改为纯 PHP krowinski/php-mysql-replication**（替代本地 mysqlbinlog CLI；消除 9.4 缺 mysql_native_password 插件导致的 code=1/1099），与 `AgentHandler::handleDump` 契约一致

- [x] **Phase G — 代理可达性状态派生 + 数据表多选 + 回滚事务开关**（2026-08-26 下午场）
  - [x] `AppState.agentReachable` + `deriveTopStatus(state)`（demo > WS connected/wsMeta > agentReachable=true > false > WS error > idle）→ 四页面 TopBar 一致
  - [x] `TraceConfig.table: string` → `string[]`；数据表 / 结果页表筛选均改 MultiSelect，互斥「全部」与具体表（互斥逻辑内聚到 `MultiSelect.exclusiveOption`）
  - [x] `MultiSelect` 支持 `string | {value,label}` options、`label`、`id`、`exclusiveOption` props；trigger 高度 36px 与 Select 对齐
  - [x] `krowinski_dump.php` 心跳 `withHeartbeatPeriod(5)`→`2`、兜底退出 `endTs+5`→`endTs+2`（去除 dump 至少卡 5 秒）
  - [x] TracePage 去除 48h 时间限制；快捷按钮加「近48小时」「近一周」
  - [x] `rollback-gen.generateRollback(changes, independentTx=false)`：共享事务（首尾各一次）默认 / 独立事务（每 xid 组包裹），RollbackPage 加 checkbox 切换

## Decisions
| # | Decision | Rationale |
|---|----------|-----------|
| 1 | 真实模式绕过 WASM，改用 agent 侧 krowinski | WASM 手写解码 NEWDECIMAL/DATETIME2 有缺陷；tpc 重建有依赖障碍；krowinski 是成熟完整库 |
| 2 | krowinski 跑独立子进程（非事件循环内） | 同步阻塞库，进 Workerman 事件循环会卡死 |
| 3 | 起点文件定位放 worker 内（非 AsyncClient peek） | AsyncClient 是单连接，无法并发 peek；探测属 binlog 领域逻辑，内聚在 worker |
| 4 | 不做文件内二分定位 | 多读部分被 start-ts 过滤，无正确性影响；仅历史很旧时稍慢 |
| 5 | 心跳兜底用本地 `time()` | krowinski 心跳 `eventInfo->timestamp` 恒为 0（库实现），不可用于比较 |
| 6 | 原生 agent binlog 解析改用纯 PHP krowinski（替代本地 mysqlbinlog） | 用户要求打包后不依赖本地二进制；本机 mysqlbinlog(9.4) 缺 mysql_native_password 插件连不上远程 5.7；与 agent-workerman 同源一致 |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
| `socket_create` 10106（Winsock） | 1 | `proc_open` env 改 `array_merge(getenv(),[密码])` |
| dump 期间 query/heartbeat 全无响应 | 1 | 发现管道 `fread` 阻塞事件循环 → stdout 改文件重定向 + Timer 轮询 |
| 探测把所有文件误判「更旧」 | 1 | dump 文件头先发 ROTATE 定位事件，`onRotate` 抢置 verdict → ROTATE 不参与判定 |
| worker 越窗不自行退出 | 1 | 心跳 `eventInfo->timestamp` 恒 0 → 兜底改本地 `time() > endTs+5` |
| `withBinLogPosition(int)` TypeError | 1 | 签名要 string → `(string)$pos` |
| 前端 `passedEnd` 永不成立 | 1 | 秒/毫秒单位错 → 1e11 阈值归一化 `toMs` |
| binlogFile 含 CRC 尾部 → 无输出 | 1 | 剥离 CRC 尾部并修正 eventSize |
| 结果页筛选栏子元素高度差 28px+ | 1 | `Select` 空 `.field-error` 占位 + `.field` column 布局 → 局部覆盖 |
| 同名连接反复保存堆积多条 | 1 | `doConnect` 每次 `newId()` → `upsertConnection` 永远 `unshift` → 先按 name 复用 id |
| 24h 追踪 0 变更（从当前尾巴 pos 读） | 3 | `probeStartFile` 定位起点文件 pos4 + `--to-last-log` |
| worker code=1 / 1099（mysql_native_password 插件缺失） | 1 | 改纯 PHP krowinski，不再 shell-out 本地 mysqlbinlog |

## Verification
- worker 独立探测（startTs=1787414500）：定位 binlog.000042，窗口内 2 条变更（NEWDECIMAL/DATETIME2 值正确）
- WS 端到端历史窗口：3 条窗口内 `binlog-change` + `binlog-end` 帧
- 集成测试 `tests/integration_test.php`：11 PASS / 0 FAIL
- 前端 `tsc --noEmit`：exit=0

## Next Steps
- [ ] 重启 8080 agent（`taskkill /F /IM php.exe; cd agent-workerman && php start.php start`）+ 刷新 Vite 5173，
      真实 UI 验证「选时间点拉取」能捕到 order 表变更、下拉可选
- [ ] 清理临时探针：`tests/_probe_dump_frames.php`、`tests/_probe_parser.php`、`tests/_probe_krowinski.php`、
      `tests/_probe_dump_flags.php`、`runtime/_e2e_*.php`、`runtime/_repro_f.php`、`runtime/kw_*.log`、`runtime/krowinski.pid`
- [ ] （可选）probeOne 内加文件内二分，精确到文件内位置（历史很旧时提速）
