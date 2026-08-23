# Progress Log — 打通 binlog 追踪 + krowinski 集成 + 时间窗口定位

## Session 2026-08-23

### 已完成（Phase A–D 全部落地并验证）
- [x] agent 组帧修复：0x00 前缀剥离 + CRC 尾部剥离 + eventSize 修正 + ROTATE 偏移 27（探针 17/18 正确）
- [x] 前端 `passedEnd` 秒/毫秒单位修复（1e11 归一化 `toMs`）
- [x] 集成 krowinski：`bin/krowinski_dump.php` 子进程 + `WsHandler` proc_open 转发 `binlog-change` 帧
- [x] 修 Windows 两大坑：env `getenv()` 全量合并（10106）、stdout 文件重定向 + Timer 轮询（事件循环卡死）
- [x] 时间窗口：worker `--start-ts/--end-ts`（行过滤 + 越窗退出 + 心跳 5s 兜底 `time()>endTs+5`）
- [x] 起点文件定位：worker 内 PDO `SHOW BINARY LOGS` + 从新到旧探测首行 ts
- [x] `binlog-end` 帧（worker 正常退出 code 0 触发，前端立即 finalize）
- [x] 前端 `ws.ts` 透传 startMs/endMs + `finalizeStructured` 窗口过滤
- [x] AGENTS.md 更新（时间定位语义 + payload 字段 + binlog-end + Windows 坑）

### 验证结果
| 项 | 结果 |
|----|------|
| worker 独立探测 startTs=1787414500 | ✅ 定位 binlog.000042，窗口内 2 条变更（NEWDECIMAL/DATETIME2 值正确） |
| WS 端到端历史窗口 | ✅ 3 条窗口内 binlog-change + binlog-end |
| 集成测试 integration_test.php | ✅ 11 PASS / 0 FAIL |
| 前端 tsc --noEmit | ✅ exit=0 |

### 关键文件改动
- `agent-workerman/bin/krowinski_dump.php`（新增）— 子进程 worker：解析 + 时间窗口 + 起点定位 + 心跳兜底
- `agent-workerman/src/WsHandler.php` — handleBinlogDump 改 proc_open；透传 startMs/endMs；binlog-end；文件轮询 drainDumpWorker
- `agent-workerman/src/Mysql/AsyncClient.php` — 0x00/CRC 剥离、eventSize 修正、ROTATE 偏移 27、binlogChecksummed
- `agent-workerman/tests/integration_test.php` — requestOnFor 跳过 binlog-change 流帧
- `frontend/src/lib/ws.ts` — binlog-end 分发（onDumpEnd）、startDump 透传 startMs/endMs
- `frontend/src/lib/session.ts` — changes 缓冲 + structuredChanges getter
- `frontend/src/hooks/useTraceRun.ts` — 真实模式 finalizeStructured（绕过 WASM）、toMs 归一化、窗口过滤
- `frontend/src/types/api.d.ts` — BinlogChangePayload 类型
- `AGENTS.md` — 架构边界 + 时间定位 + Windows 坑

## Session 2026-08-23（续）

### 结果页筛选栏对齐修复（本轮）
- [x] `.filter-bar` 单行对齐：子元素高度 26px 拉齐（`.filter-bar .field { min-width:0; margin-bottom:0; }` +
      `.filter-bar .field-error { display:none; }`），未改 `Select` 组件。
- [x] `evaluate_script` 复测四子元素 centerY 全部 123，`.filter-bar` 高 72→26。
- 修改：`frontend/src/styles/components.css`（局部覆盖 + 必要注释）。

### 已存连接同名覆盖修复（本轮）
- [x] `ConnectPage.doConnect`：`newId()` 前按 `name` 查找 `saved` 里已有条目的 `id`，有则复用。
      `upsertConnection` 就能正确 `findIndex` 并覆盖，不再同名堆积。
- [x] 浏览器内脚本验证：同名 2 次 + 异名 1 次 → 最终 2 条、2 个唯一 id。
- 修改：`frontend/src/pages/ConnectPage.tsx`（`doConnect` 内新增 `connName`/`existingId` + 注释）。

### 待办（下次继续）
- [ ] 重启 8080 agent + 刷新 Vite 5173，真实 UI 验证
- [ ] 清理临时探针文件（tests/_probe_*、runtime/_e2e_*、runtime/kw_*.log 等）
- [ ] （可选）文件内二分定位提速
