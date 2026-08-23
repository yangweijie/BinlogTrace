# DMS — MySQL 数据追踪工具

一个浏览器端 MySQL binlog 实时数据追踪工具，对标阿里云 DMS「数据追踪」功能。

核心思路：将繁重的 binlog 字节流解码工作放到浏览器 WASM 里执行，用一个原生二进制 TCP 代理连接 MySQL，全程前端驱动，服务端仅做协议透传，无持久化存储。

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
│       │         ┌─────▼──────────┐         │             │
│       │         │  Web Worker    │         │             │
│       │         │  (Parser WASM) │         │             │
│       │         └────────────────┘         │             │
│       │               │                    │             │
│       └───────────────┼────────────────────┘             │
│                       ▼                                  │
│              WebSocket (v2)                               │
└───────────────────────┬─────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────┐
│           Agent (原生二进制)                               │
│  监听 :8080，WebSocket TCP 代理                            │
│  内置 MySQL 协议实现（握手 / 认证 / 查询 / Binlog Dump）   │
└───────────────────────┬─────────────────────────────────┘
                        │ COM_BINLOG_DUMP / COM_QUERY
                        ▼
┌─────────────────────────────────────────────────────────┐
│                     MySQL Server                          │
│  要求：log_bin=ON, binlog_format=ROW                     │
└─────────────────────────────────────────────────────────┘
```

项目由三大组件构成：

| 组件 | 技术栈 | 编译目标 | 角色 |
|------|--------|---------|------|
| `parser/` | PHP → TypePHP → WASM | 浏览器 WASM（browser） | 在 Web Worker 中解码 binlog 字节流，生成变更列表和回滚 SQL |
| `agent/` | PHP → TypePHP → 原生二进制 | 本地 native binary | 监听本机端口，通过 WebSocket 把浏览器和 MySQL 双向打通 |
| `frontend/` | React 18 + Vite 7 + TypeScript | SPA | 提供连接、追踪、结果、回滚四个页面 |

---

## 快速开始

### 前置条件

| 项目 | 要求 |
|------|------|
| PHP | 8.x（仅用于源码 lint，不直接运行业务代码） |
| Node.js | 18+（前端开发） |
| TypePHP 编译器 | 用于编译 `parser/` 和 `agent/`（见下方说明） |
| MySQL | 5.7 / 8.0，需满足以下配置 |

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

### 编译

`parser/` 和 `agent/` 中的 PHP 源码**不能直接用 `php` 运行**，必须通过 TypePHP 编译器 `bin/tpc.php` 编译（该编译器位于独立的 TypePHP 编译器仓库中）。

```bash
# 编译 Parser 为浏览器 WASM
php bin/tpc.php parser/project.yml

# 编译 Agent 为原生二进制
php bin/tpc.php agent/ -o binlog-agent

# Dry run（仅生成 C++ 源码，不编译链接）
php bin/tpc.php agent/ --dry --build-dir /tmp/typephp-build
```

### 启动 Agent

```bash
./binlog-agent --port 8080
```

Agent 默认绑定 `0.0.0.0:8080`，接受 WebSocket 连接。

### 启动前端

```bash
cd frontend
npm install
npm run dev    # 启动开发服务器 → http://127.0.0.1:5173
```

---

## 使用说明

### 1. 连接 MySQL（`/`）

在连接页填写 MySQL 连接信息（主机、端口、用户名、密码、数据库名），系统会通过 Agent 发起连接并调用 `checkBinlogCfg` 检查 binlog 配置是否满足追踪要求。

### 2. 实时追踪（`/trace`）

连接成功后进入追踪页，系统会自动发起 `COM_BINLOG_DUMP` 并开始接收 binlog 事件。每条事件以 base64 编码的 JSON 帧通过 WebSocket 推送到浏览器，由 Web Worker 中的 Parser WASM 实时解码为结构化的变更记录。

### 3. 查看变更（`/trace/result`）

所有解码后的变更在结果页展示，支持按 schema / 表 / 操作类型筛选。点击单条变更可查看前后值 diff。

### 4. 生成回滚 SQL（`/rollback`）

选中一个或多个变更，系统按 `xid` 分组、按 `timestamp` 排序，生成对应的回滚 SQL（`INSERT` → `DELETE`，`DELETE` → `INSERT`，`UPDATE` → `UPDATE`），支持复制和执行。

---

## 组件详解

### Parser（`parser/`）

PHP 源码编译为浏览器 WASM，在 dedicated Web Worker 中运行，不阻塞 UI 主线程。

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

### Agent（`agent/`）

PHP 源码编译为原生二进制，实现 WebSocket 到 MySQL 的 TCP 代理。

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
| `/` | `ConnectPage` | 配置 MySQL 连接、检查 binlog 配置（调用 `checkBinlogCfg`） |
| `/trace` | `TracePage` | 建立 WS 连接 → 开始追踪 → 接收 binlog-event → 流式解析（调用 `parseBinlog`） |
| `/trace/result` | `ResultPage` | 展示变更列表，可点击单条打开详情查看前后值 diff |
| `/rollback` | `RollbackPage` | 选中变更 → 生成回滚 SQL（调用 `generateRollback`） |

WASM 在 dedicated Web Worker（`src/workers/parser.worker.ts`）中运行，通过 `@bytecodealliance/preview2-shim` + `@bytecodealliance/jco` 调用 WASM Component Model。

---

## WebSocket 协议（v2）

### 统一帧结构

所有消息均为 JSON 字符串，作为 WebSocket text 帧载荷传输：

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
| `connect` | `{host, port, user, password, database, connectTimeoutMs, serverId}` | 连接 MySQL |
| `binlog-dump` | `{binlogFile, binlogPos, slaveFlags}` | 发起 binlog dump |
| `query` | `{sql}` | 只读 SQL（仅允许 `SELECT` / `SHOW`） |
| `close` | — | 关闭连接 |
| `heartbeat` | — | 心跳 |

### Agent → 客户端（响应）

| type | payload | 说明 |
|------|---------|------|
| `connected` | `{hasBinlog, binlogFile, binlogPos, gtid, ...}` | 连接成功 + binlog 元数据 |
| `dump-started` | `{binlogFile, binlogPos}` | Dump 启动 |
| `binlog-event` | `{raw(base64), eventType, binlogFile, binlogPos, timestamp, serverId}` | 单条 binlog 事件 |
| `query-result` | `{columns: [{name, type}], rows: [...]}` | 查询结果 |
| `error` | `{code, message}` | 错误 |
| `heartbeat` | `{ts, binlogPos}` | 心跳（约 15s 间隔） |

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
- Agent 和 Parser 均为**单线程 NTS**（Non-Thread-Safe），无并发控制，不适合高并发场景

---

## 技术栈

### Parser

| 依赖 | 用途 |
|------|------|
| TypePHP | PHP → WASM 编译器 |
| `@bytecodealliance/preview2-shim` | WASM Component Model 运行时 |
| `@bytecodealliance/jco` | WASM 函数调用桥接 |

### Agent

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
| `@bytecodealliance/preview2-shim` | — | WASM 运行时 |
| `@bytecodealliance/jco` | — | WASM 调用 |

---

## 开发指南

### PHP 源码限制

`parser/` 和 `agent/` 中的 PHP 源码必须遵守 TypePHP 编译器的 PHP 子集约束：

- **必须**：每个文件顶部 `declare(strict_types=1);`
- **禁止**：闭包（closures）、箭头函数、生成器（`yield`）、Fibers、动态分发（`$obj->$method()`）
- **WASM 导出**：`#[WasmExport]` 函数仅支持 `string` 输入 / `string` 输出
- **原生二进制**：必须定义 `function main(int $argc, array $argv): void`
- **排序**：使用内联插入排序，不要用 `uksort` 配合闭包
- **JSON**：`json_encode` / `json_decode` 支持 `flags:` 命名参数

详见 `docs/INCOMPATIBLE_PHP_FEATURES.md`。

### 常用命令

```bash
# PHP 源码 lint
find parser/src agent/src -name '*.php' -exec php -l {} +

# 编译 Parser WASM（需 TypePHP 编译器）
php bin/tpc.php parser/project.yml

# 编译 Agent 原生二进制
php bin/tpc.php agent/ -o binlog-agent

# Agent dry run（仅生成 C++，不编译链接）
php bin/tpc.php agent/ --dry --build-dir /tmp/typephp-build

# 运行 Agent
./binlog-agent --port 8080

# 前端开发
cd frontend && npm install && npm run dev

# 前端构建
cd frontend && npm run build

# 前端类型检查
cd frontend && npm run type-check
```

### 忽略的文件

以下目录为编译产物，不应手动编辑：

- `parser/build/` — TypePHP 生成的 C++ 源码及中间文件
- `parser/component/` — 编译后的 WASM 产物（`.wasm` / `.lib` / `.exp`）
- `agent/build/` — TypePHP 生成的 C++ 源码及中间文件
- `agent/binlog_agent.exe` / `.lib` / `.exp` — 编译后的原生二进制
- `frontend/dist/` — Vite 构建输出

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
├── parser/                 # binlog 解码器（PHP → WASM）
│   ├── project.yml         #   TypePHP 编译配置
│   └── src/                #   PHP 源码
│       ├── BinlogExport.php     #   WASM 导出函数入口
│       ├── Codec/             #   MySQL binlog 值类型解码
│       ├── Change/            #   变更记录构建
│       ├── Event/             #   binlog 事件解码
│       └── Rollback/          #   回滚 SQL 生成
├── agent/                  # WebSocket TCP 代理（PHP → 原生二进制）
│   ├── project.yml         #   TypePHP 编译配置
│   └── src/                #   PHP 源码
│       ├── AgentMain.php        #   TCP 监听主循环
│       ├── ConnectionHandler.php#   连接生命周期管理
│       ├── MySQL/             #   MySQL 协议实现
│       ├── Protocol/          #   WS 协议定义
│       └── WsFrameCodec.php   #   WebSocket 帧编解码
├── frontend/               # 浏览器前端（React + TypeScript）
│   ├── src/                #   TypeScript 源码
│   └── package.json
├── docs/                   # TypePHP 编译器设计文档
├── vendor/                 # Composer autoload（仅加载，无运行时依赖）
├── findings.md             # 跨模块设计决策记录
├── AGENTS.md               # 项目说明（面向 AI 开发助手）
└── README.md               # 本文件
```

---

## 许可证

本仓库包含的 `parser/` 和 `agent/` 源码需配合独立的 TypePHP 编译器仓库使用。TypePHP 编译器的构建与测试方法请参考其仓库文档。