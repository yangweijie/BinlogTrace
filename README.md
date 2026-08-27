# DMS — MySQL 数据追踪工具

一个浏览器端 MySQL binlog 实时数据追踪工具，对标阿里云 DMS「数据追踪」功能。

核心思路：浏览器前端驱动，Agent 连接 MySQL 并实时推送 binlog 行级变更；前端将变更渲染为结构化记录、支持筛选与回滚 SQL 生成。

提供两种运行模式：

- **真实模式（推荐）**：Agent 直接对接真实 MySQL，由服务端（`agent-workerman` 或 `webman`）解析 binlog 行事件，通过 HTTP + SSE 向前端推送结构化的 `binlog-change` 帧。无需 TypePHP 编译器、无需浏览器 WASM。
- **演示模式**：无真实 MySQL 时，前端用 `mock-agent` 模拟协议 v2 数据流；浏览器内的 Parser WASM（`parser/`）负责解码 binlog 字节流，用于 UI/协议联调。

> 注：早期设计依赖 TypePHP 编译器把 `parser/`/`agent/` 编译为 WASM / 原生二进制。当前真实模式已迁移到纯 PHP Agent（`agent-workerman` / `webman`），不再需要编译器即可运行。`parser/` 与 `agent/` 保留作为 TypePHP 编译路径参考。

---

## 架构概览

```
┌─────────────────────────────────────────────────────────┐
│                     浏览器 (React SPA)                    │
│  ┌─────────┐  ┌──────────────┐  ┌────────────────────┐  │
│  │ Connect │  │  Trace Page   │  │  Result / Rollback │  │
│  │  连接页 │→ │  追踪页       │→ │  结果 / 回滚页     │  │
│  └────┬────┘  └──────┬───────┘  └─────────┬──────────┘  │
│       │               │                    │             │
│       │   HTTP REST + SSE (v2 帧)          │             │
│       │   POST /connect /dump /query /close │            │
└───────┼───────────────┼────────────────────┼─────────────┘
        │               │                    │
        ▼               ▼                    │
┌───────────────────────────────────┐      │
│   Agent (纯 PHP, 真实模式)          │      │
│   agent-workerman  OR  webman       │      │
│   krowinski 子进程解析 binlog 行事件 │      │
└───────────────┬───────────────────┘      │
                │ COM_BINLOG_DUMP / COM_QUERY
                ▼
┌─────────────────────────────────────────────────────────┐
│                     MySQL Server                          │
│  要求：log_bin=ON, binlog_format=ROW                     │
└─────────────────────────────────────────────────────────┘

  [演示模式] 无真实 MySQL 时，前端 mock-agent 模拟 v2 帧，
             Web Worker 内 Parser WASM 负责解码 binlog 字节流。
```

项目由以下组件构成：

| 组件 | 技术栈 | 编译目标 | 角色 |
|------|--------|---------|------|
| `frontend/` | React 18 + Vite 7 + TypeScript | SPA | 提供连接、追踪、结果、回滚四个页面；HTTP + SSE 客户端 |
| `agent-workerman/` | 纯 PHP (Workerman 5) | 本地 PHP 进程 | **真实模式** Agent：HTTP 服务 + krowinski 子进程解析 binlog，无需编译器 |
| `webman/` | 纯 PHP (Webman) | 本地 PHP 进程 | **真实模式** Agent：基于 Webman 框架的 HTTP 入口（同 `WsHandler` 逻辑） |
| `parser/` | PHP → TypePHP → WASM | 浏览器 WASM | **演示模式** 在 Web Worker 解码 binlog 字节流（TypePHP 编译路径，可选） |
| `agent/` | PHP → TypePHP → 原生二进制 | 本地 native binary | 早期 TypePHP 编译路径参考（WebSocket 代理，可选） |

---

## 快速开始

### 前置条件

| 项目 | 要求 |
|------|------|
| PHP | 8.x（真实模式 Agent 运行环境；`agent-workerman` / `webman` 直接用 `php` 运行） |
| Composer | 用于安装 `agent-workerman` / `webman` 的 workerman 依赖 |
| Node.js | 18+（前端开发） |
| MySQL | 5.7 / 8.0，需满足以下配置 |
| TypePHP 编译器 | **仅**演示模式的 `parser/` WASM / `agent/` 原生二进制需要；真实模式不需要 |

#### MySQL 配置要求

```ini
[mysqld]
log_bin        = ON
binlog_format  = ROW
```

用户需具备以下权限：

```sql
GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'dms_user'@'%';
```

> **MySQL 8.0.20+**：如果启用了事务压缩（`binlog_transaction_compression=ON`），事件将无法解码，会触发 `1012` 错误。如需使用，请先关闭该选项。

### A. 真实模式（推荐，无需编译器）

#### 1. 启动 Agent（二选一）

**方式一：agent-workerman（Workerman 5）**

```bash
cd agent-workerman
composer install
php start.php start --port 8080     # 默认 8080，--port 可覆盖；另有 stop / restart 子命令
```

**方式二：webman**

```bash
cd webman
composer install
php windows.php start               # Windows；类 Unix 用 php start.php start
```

两者均监听 `0.0.0.0:8080`，提供 HTTP REST 端点（`/connect` `/query` `/dump` `/close` `/ping`）。

#### 2. 启动前端

```bash
cd frontend
npm install
npm run dev    # 启动开发服务器 → http://127.0.0.1:5173
```

前端连接 Agent 的 `http://127.0.0.1:8080`（可在连接页设置 `agentUrl`）。真实模式下 binlog 行事件由 Agent 解析后以 `binlog-change` 帧经 SSE 推送，前端无需 WASM。

### B. 演示模式（需 TypePHP 编译器，可选）

`parser/` 和 `agent/` 中的 PHP 源码**不能直接用 `php` 运行**，必须通过 TypePHP 编译器 `bin/tpc.php` 编译（该编译器位于独立的 TypePHP 编译器仓库中）。

```bash
# 编译 Parser 为浏览器 WASM
php bin/tpc.php parser/project.yml

# 编译 Agent 为原生二进制
php bin/tpc.php agent/ -o binlog-agent

# Dry run（仅生成 C++ 源码，不编译链接）
php bin/tpc.php agent/ --dry --build-dir /tmp/typephp-build
```

```bash
./binlog-agent --port 8080
```

无真实 MySQL 时，前端可用 `mock-agent` 走演示流程（WASM 解码路径）。

---

## 使用说明

### 1. 连接 MySQL（`/`）

在连接页填写 MySQL 连接信息（主机、端口、用户名、密码、数据库名）与 Agent 地址（默认 `http://127.0.0.1:8080`）。前端 `POST /connect` 经 Agent 发起 MySQL 连接；真实模式下由 Agent 采集 binlog 元数据，演示模式下调用 WASM `checkBinlogCfg` 检查 binlog 配置是否满足追踪要求。

### 2. 实时追踪（`/trace`）

连接成功后进入追踪页，前端 `POST /dump` 发起 binlog dump 并持续接收 SSE 流：

- **真实模式**：Agent 派生 `bin/krowinski_dump.php` 子进程读取 binlog 行事件，解析后按行推送 `binlog-change` 帧（含 `kind` / `schema` / `table` / `columns` / `before` / `after` / `xid` / `timestamp` / `binlogFile` / `binlogPos`）。前端直接映射为变更记录，**不经过 WASM**。
- **演示模式**：事件字节流经 WebSocket 推送，由 Web Worker 中的 Parser WASM 实时解码为结构化变更。

支持历史点定位：`/dump` 可携带 `startMs` / `endMs`（epoch 毫秒）限定时间窗口。

### 3. 查看变更（`/trace/result`）

所有解码后的变更在结果页展示，支持按 schema / 表 / 操作类型筛选。点击单条变更可查看前后值 diff。

### 4. 生成回滚 SQL（`/rollback`）

选中一个或多个变更，系统按 `xid` 分组、按 `timestamp` 排序，生成对应的回滚 SQL（`INSERT` → `DELETE`，`DELETE` → `INSERT`，`UPDATE` → `UPDATE`），支持复制和执行。

> 演示模式的回滚 SQL 由 WASM `generateRollback` 生成；真实模式下回滚 SQL 在前端基于已接收的 `binlog-change` 记录生成。

---

## 组件详解

### Agent（真实模式，`agent-workerman/` 与 `webman/`）

两个目录实现**同一套** binlog 代理逻辑（共享 `DmsAgent\WsHandler` / `MetaGatherer` / `SessionManager` 等），区别仅在进程/框架入口：

| 目录 | 入口 | 传输层 | 说明 |
|------|------|--------|------|
| `agent-workerman/` | `start.php`（Workerman 5 HTTP Worker） | HTTP REST + 分块/SSE 流 | 默认 `0.0.0.0:8080`，`--port` 可覆盖，带 `stop` / `restart` 子命令 |
| `webman/` | `start.php` / `windows.php`（Webman 框架） | HTTP REST + SSE 流 | 基于 Webman 路由（`config/route.php`），逻辑委托给 `App\Controller\AgentController` |

**双连接模型**：每个浏览器会话（`session` token）持有一个 `WsHandler`；dump 阶段保持**一条长生命周期** MySQL 连接（binlog 事件流），meta/schema 查询走**另一条惰性连接**（串行队列），互不阻塞。

**真实模式解析流程**：`/dump` 派生 `bin/krowinski_dump.php` 作为**独立 PHP 子进程**（krowinski 是同步阻塞库，不能在事件循环内运行）。子进程逐行向 stdout 输出 JSON 变更，Agent 以 `binlog-change` 帧经 SSE 转发。子进程正常退出（exit 0）时 Agent 发送 `binlog-end` 帧，前端据此结束会话。

**关键内部模块**：

| 文件 | 功能 |
|------|------|
| `WsHandler.php` | 会话全生命周期：`onConnect` / `onDump`（SSE 长流）/ `onQuery` / `onCloseRequest` |
| `SessionManager.php` | session token 注册 / 查找 / 计数 |
| `MetaGatherer.php` | 连接后采集 binlog 元数据（当前 binlog 文件名、位置、GTID 等） |
| `Mysql/AsyncClient.php` | 手写 MySQL 协议状态机（`auth → idle → result/binlog`），修复 `agent/` 的两处协议 bug |
| `AgentConstants.php` | 版本号、协议常量、错误码 |
| `bin/krowinski_dump.php` | krowinski 子进程入口，逐行输出 binlog 变更 JSON |

> `agent-workerman/src` 与 `webman/app/DmsAgent` 内容一致；改动需同步两侧（维护约定见 AGENTS.md）。

### Parser（`parser/`，演示模式，可选）

PHP 源码编译为浏览器 WASM，在 dedicated Web Worker 中运行，仅用于**无真实 MySQL 的演示/协议联调**。

**编译配置**（`parser/project.yml`）：
- `mode: library`
- `wasm: browser`
- `wasm-package: typephp:binlog-parser@1.0.0`

**WASM 导出函数**：

| WASM 函数名 | 前端调用名 | 功能 |
|-------------|-----------|------|
| `parse-binlog` | `parseBinlog` | 输入 base64 编码的 binlog 事件数组 + 表元数据，输出 `{ok, changes[], warnings[]}` |
| `generate-rollback` | `generateRollback` | 输入 changes 数组，输出按 xid 分组、按 timestamp 排序的回滚 SQL |
| `check-binlog-cfg` | `checkBinlogCfg` | 输入 MySQL 配置信息，检查 binlog 是否可追踪，输出 `CheckIssue[]` |

> WASM 导出函数只接受 `string` 输入、返回 `string` 输出。前端通过 `@bytecodealliance/jco` 调用，Jco 会自动将 kebab-case 转为 camelCase。

**关键内部模块**：

| 文件 | 功能 |
|------|------|
| `Event/EventDecoder.php` | binlog 事件头部解析（19 字节：timestamp / eventType / serverId / eventSize / logPos / flags） |
| `Event/TableMapCache.php` | TABLE_MAP 事件解析 + `tableId` → 表元数据缓存；MySQL 5.7 MINIMAL 模式兜底时用 INFORMATION_SCHEMA 补充列名 |
| `Codec/TypeDecoder.php` | MySQL binlog 内部编码解码（DECIMAL / DATETIME2 / TIME2 / NEWDATE / length-coded） |
| `Change/ChangeBuilder.php` | 解码后的行数据 → 标准化 Change 对象 |
| `Rollback/RollbackGenerator.php` | flashback 回滚 SQL 生成 |

### Agent（`agent/`，TypePHP 编译路径参考，可选）

PHP 源码编译为原生二进制，实现 WebSocket 到 MySQL 的 TCP 代理。属早期设计，真实模式已迁移到纯 PHP Agent。

**编译配置**（`agent/project.yml`）：
- `mode: bin`
- 产物：`binlog-agent`

**关键内部模块**：

| 文件 | 功能 |
|------|------|
| `AgentMain.php` | TCP 监听主循环，非阻塞 `stream_select` 轮询，每个浏览器连接分配一个 `ConnectionHandler` |
| `ConnectionHandler.php` | 单连接全生命周期：WS 握手 → 帧分派 → MySQL 交互 → 事件透传 |
| `MySQL/Client.php` | 原生实现 MySQL 通信协议：握手 / 认证（`mysql_native_password` SHA1）/ `COM_QUERY`（只读） / `COM_BINLOG_DUMP` / 事件读取 |
| `MySQL/Protocol.php` | 底层 MySQL 长度编码包协议 |
| `WsFrameCodec.php` | WebSocket 帧编解码（RFC 6455） |
| `MetaGatherer.php` | 连接后自动采集 binlog 元数据（当前 binlog 文件名、位置、GTID 等） |
| `Protocol/Frame.php` | WS 协议常量 + 13 个错误码定义 |

### Frontend（`frontend/`）

React 18 + Vite 7 + TypeScript 单页应用，hash 路由。

| 路径 | 页面 | 功能 |
|------|------|------|
| `/` | `ConnectPage` | 配置 MySQL 连接与 Agent 地址；真实模式由 Agent 采集 binlog 元数据，演示模式调用 `checkBinlogCfg` |
| `/trace` | `TracePage` | `POST /dump` 发起追踪 → SSE 接收 `binlog-change` → 流式映射为变更记录（演示模式下经 WASM 解码） |
| `/trace/result` | `ResultPage` | 展示变更列表，可点击单条打开详情查看前后值 diff |
| `/rollback` | `RollbackPage` | 选中变更 → 生成回滚 SQL（演示模式调用 `generateRollback`） |

前端客户端位于 `src/lib/ws.ts`（**HTTP REST + SSE** 实现，对外仍暴露原 `connect/startDump/query/close` 契约，UI 无需改动）。演示模式的 WASM 在 dedicated Web Worker（`src/workers/parser.worker.ts`）中运行，通过 `@bytecodealliance/preview2-shim` + `@bytecodealliance/jco` 调用 WASM Component Model。

---

## Agent 协议（v2）

> 真实模式使用 **HTTP REST + SSE**，不再依赖 WebSocket。所有帧为协议 v2 的 JSON 结构（`{v, id, type, ts, payload}`）。演示模式（TypePHP `agent/`）仍走 WebSocket，帧结构相同。

### HTTP 端点（真实模式）

| 端点 | 方法 | 行为 |
|------|------|------|
| `/connect` | POST | 请求-响应：建立 MySQL 会话，返回 `connected`（含 `session` token）或 `error` |
| `/query` | POST | 请求-响应：只读 SQL（`SELECT` / `SHOW`），返回 `query-result` 或 `error`（body 带 `payload.session`） |
| `/dump` | POST | **SSE 长流**：持续推送 `binlog-change` / `heartbeat` / `error` / `binlog-end`（`binlog-dump` 与 `dump` 路由等价） |
| `/close` | POST | 销毁会话 |
| `/ping` | GET/POST | 健康检查（无认证），返回服务版本、PHP 版本、会话数 |

### 统一帧结构

所有消息均为 JSON 字符串，作为 HTTP 响应体或 SSE `data:` 行传输：

```json
{
  "v": 2,
  "id": "客户端请求 ID",
  "type": "消息类型",
  "ts": 1700000000000,
  "payload": {}
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `v` | number | 协议版本（当前为 2） |
| `id` | string | 客户端生成的请求 ID，用于匹配响应 |
| `type` | string | 消息类型（见下表） |
| `ts` | number | Unix 时间戳（毫秒） |
| `payload` | object | 消息体，格式依 `type` 而定 |

### 客户端 → Agent（请求）

| type | payload | 说明 |
|------|---------|------|
| `connect` | `{host, port, user, password, database, connectTimeoutMs, serverId}` | 连接 MySQL，返回 `connected`（含 `session`） |
| `binlog-dump` / `dump` | `{session, binlogFile?, binlogPos?, startMs?, endMs?, slaveFlags}` | 发起 binlog dump；`startMs`/`endMs` 限定时间窗口 |
| `query` | `{session, sql}` | 只读 SQL（仅允许 `SELECT` / `SHOW`） |
| `close` | `{session}` | 关闭会话 |

### Agent → 客户端（响应）

| type | payload | 说明 |
|------|---------|------|
| `connected` | `{hasBinlog, binlogFile, binlogPos, gtid, session, ...}` | 连接成功 + binlog 元数据 + 会话 token |
| `dump-started` | `{binlogFile, binlogPos}` | Dump 启动 |
| `binlog-change` | `{kind, schema, table, columns, before, after, xid, timestamp, binlogFile, binlogPos}` | 单条行级变更（真实模式，SSE 流） |
| `binlog-event` | `{raw(base64), eventType, binlogFile, binlogPos, timestamp, serverId}` | 单条 binlog 事件（演示模式 WS） |
| `binlog-end` | `{}` | dump 子进程正常结束，前端据此 finalize 会话 |
| `query-result` | `{columns: [{name, type}], rows: [...]}` | 查询结果 |
| `error` | `{code, message}` | 错误 |
| `heartbeat` | `{ts, binlogPos}` | 心跳（约 5s 间隔，用于时间窗口兜底） |

### 错误码

| 错误码 | 常量 | 说明 |
|--------|------|------|
| 1001 | `AUTH_FAILED` | 认证失败 |
| 1002 | `NETWORK_UNREACHABLE` | 网络不可达 |
| 1003 | `BINLOG_DISABLED` | binlog 未启用 |
| 1004 | `PERMISSION_DENIED` | 权限不足 |
| 1005 | `PARSE_ERROR` | 解析错误 |
| 1006 | `PROXY_NOT_READY` | 代理未就绪 |
| 1007 | `TIMEOUT` | 超时 |
| 1008 | `INVALID_PARAM` | 参数无效 |
| 1009 | `SERVER_ID_CONFLICT` | serverId 冲突 |
| 1010 | `PROTOCOL_ERROR` | 协议错误 |
| 1011 | `BINLOG_POSITION_INVALID` | binlog 位置无效 |
| 1012 | `TRANSACTION_COMPRESSED` | 事务被压缩，无法解码 |
| 1013 | `META_MISSING` | 表元数据缺失 |

---

## 部署与安全

> ⚠️ **Agent 默认绑定 `0.0.0.0`，仅适合内网使用。**

- Agent 内置**无认证机制**，对外暴露前必须加反向代理（如 Nginx + TLS + 认证）或防火墙限制来源
- 仅在内网环境中直接暴露 Agent 端口
- Agent 仅允许执行只读 SQL（`SELECT` / `SHOW`），拒绝写操作
- `agent-workerman` / `webman` 为单进程单 Worker（`worker->count = 1`），dump 阶段会派生 krowinski 子进程，不适合高并发场景

---

## 技术栈

### Agent（真实模式，`agent-workerman/` / `webman/`）

| 依赖 | 用途 |
|------|------|
| `workerman/workerman` (5.x) | 事件循环 / HTTP Worker（`agent-workerman`） |
| `webman/framework` | HTTP 框架与路由（`webman`） |
| `krowinski/php-mysql-replication` | 同步 binlog 复制解析（运行在独立子进程中） |

### Parser（演示模式，可选）

| 依赖 | 用途 |
|------|------|
| TypePHP | PHP → WASM 编译器 |
| `@bytecodealliance/preview2-shim` | WASM Component Model 运行时 |
| `@bytecodealliance/jco` | WASM 函数调用桥接 |

### Agent（`agent/`，TypePHP 编译路径参考，可选）

| 依赖 | 用途 |
|------|------|
| TypePHP | PHP → 原生二进制编译器 |

### Frontend

| 依赖 | 版本 | 用途 |
|------|------|------|
| React | 18.3 | UI 框架 |
| Vite | 7 | 构建工具 |
| TypeScript | 5.8 | 类型系统 |
| `lucide-react` | — | 图标库 |
| `@bytecodealliance/preview2-shim` | — | WASM 运行时（演示模式） |
| `@bytecodealliance/jco` | — | WASM 调用（演示模式） |

---

## 开发指南

### PHP 源码限制

仅 `parser/` 和 `agent/` 中的 PHP 源码（TypePHP 编译路径）需遵守 TypePHP 编译器的 PHP 子集约束：

- **必须**：每个文件顶部 `declare(strict_types=1);`
- **禁止**：闭包（closures）、箭头函数、生成器（`yield`）、Fibers、动态分发（`$obj->$method()`）
- **WASM 导出**：`#[WasmExport]` 函数仅支持 `string` 输入 / `string` 输出
- **原生二进制**：必须定义 `function main(int $argc, array $argv): void`
- **排序**：使用内联插入排序，不要用 `uksort` 配合闭包
- **JSON**：`json_encode` / `json_decode` 支持 `flags:` 命名参数

> `agent-workerman/` 与 `webman/` 是普通 PHP（现代 PHP 8 全特性），不受以上限制。

详见 `docs/INCOMPATIBLE_PHP_FEATURES.md`。

### 常用命令

```bash
# —— 真实模式（无需编译器）——
# 安装依赖
cd agent-workerman && composer install      # 或 cd webman && composer install

# 启动 Agent（agent-workerman，默认 8080）
php agent-workerman/start.php start --port 8080

# 启动 Agent（webman，Windows）
php webman/windows.php start

# 健康检查
curl http://127.0.0.1:8080/ping

# 自测（agent-workerman/tests，按需选择）
php agent-workerman/tests/ws_smoke_test.php   # 冒烟测试（无需 MySQL）
node agent-workerman/tests/node_ws_check.mjs  # Node WS 握手检查
php agent-workerman/tests/pdo_check.php       # 用 PDO 校验 MySQL 账号
php agent-workerman/tests/integration_test.php # 需真实 MySQL 127.0.0.1:3306 root/root, db shengyibao

# —— 演示模式（需 TypePHP 编译器）——
# PHP 源码 lint
find parser/src agent/src -name '*.php' -exec php -l {} +

# 编译 Parser WASM
php bin/tpc.php parser/project.yml

# 编译 Agent 原生二进制
php bin/tpc.php agent/ -o binlog-agent

# Agent dry run（仅生成 C++，不编译链接）
php bin/tpc.php agent/ --dry --build-dir /tmp/typephp-build

# 运行 Agent
./binlog-agent --port 8080

# —— 前端 ——
cd frontend && npm install && npm run dev    # 开发
cd frontend && npm run build                 # 构建
cd frontend && npm run type-check            # 类型检查
```

### 忽略的文件

以下目录为编译产物或运行时生成，不应手动编辑：

- `parser/build/` — TypePHP 生成的 C++ 源码及中间文件
- `parser/component/` — 编译后的 WASM 产物（`.wasm` / `.lib` / `.exp`）
- `agent/build/` — TypePHP 生成的 C++ 源码及中间文件
- `agent/binlog_agent.exe` / `.lib` / `.exp` — 编译后的原生二进制
- `frontend/dist/` — Vite 构建输出
- `agent-workerman/runtime/` `webman/runtime/` — 运行时日志与 krowinski 子进程输出文件

### 关键文档

| 文档 | 内容 |
|------|------|
| `docs/INCOMPATIBLE_PHP_FEATURES.md` | TypePHP 不支持的 PHP 特性清单 |
| `docs/PHP_INCOMPATIBILITY_CLASSIFICATION.md` | 硬限制 vs 有意规则 vs 待处理 分类 |
| `docs/COMPILATION_MODES.md` | bin / ext / lib 编译模式与 `main()` 要求 |
| `docs/WASI_BUILD.md` | WASI / WASM 构建前置条件 |
| `findings.md` | Phase 3 跨模块设计决策 |

---

## 目录结构

```
typephp_dms/
├── frontend/               # 浏览器前端（React + TypeScript，HTTP+SSE 客户端）
│   ├── src/
│   │   ├── lib/ws.ts         #   HTTP REST + SSE 客户端（协议 v2）
│   │   ├── lib/mock-agent.ts #   演示模式模拟数据
│   │   ├── workers/parser.worker.ts # 演示模式 WASM 解码 Worker
│   │   ├── context/AppContext.tsx
│   │   └── hooks/useTraceRun.ts
│   └── package.json
├── agent-workerman/        # 真实模式 Agent（纯 PHP / Workerman 5，推荐）
│   ├── start.php           #   HTTP Worker 入口（--port 可覆盖）
│   ├── src/                #   DmsAgent\ 逻辑（WsHandler 等）
│   ├── bin/krowinski_dump.php # krowinski 子进程（binlog 解析）
│   └── tests/              #   自测脚本（smoke / integration / pdo ...）
├── webman/                 # 真实模式 Agent（Webman 框架入口）
│   ├── start.php / windows.php
│   ├── app/DmsAgent/       #   与 agent-workerman/src 同套逻辑
│   ├── app/Controller/AgentController.php # 路由委托
│   └── config/route.php
├── parser/                 # 演示模式 binlog 解码器（PHP → WASM，可选）
│   ├── project.yml         #   TypePHP 编译配置
│   └── src/
│       ├── BinlogExport.php     #   WASM 导出函数入口
│       ├── Codec/             #   MySQL binlog 值类型解码
│       ├── Change/            #   变更记录构建
│       ├── Event/             #   binlog 事件解码
│       └── Rollback/          #   回滚 SQL 生成
├── agent/                  # TypePHP 编译路径参考（原生二进制，可选）
│   ├── project.yml
│   └── src/                #   AgentMain / ConnectionHandler / MySQL / WsFrameCodec
├── docs/                   # TypePHP 编译器设计文档
├── vendor/                 # Composer autoload（仅加载，无运行时依赖）
├── findings.md             # 跨模块设计决策记录
├── AGENTS.md               # 项目说明（面向 AI 开发助手）
└── README.md               # 本文件
```

---

## 许可证

真实模式组件 `agent-workerman/`、`webman/`、`frontend/` 与 `parser/`、`agent/` 源码均为本项目内容。其中 `parser/` 与 `agent/` 若走 TypePHP 编译路径需配合独立的 TypePHP 编译器仓库；其构建与测试方法请参考 TypePHP 编译器仓库文档。