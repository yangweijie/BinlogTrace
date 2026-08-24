# DMS Agent — 轻量 MySQL binlog 追踪代理

一个**轻量 TypePHP 风格 CLI 项目**：通过原生 PHP HTTP 服务，承载 MySQL binlog 实时追踪（连接测试、元数据采集、只读查询、binlog 解析与推送）。

## 设计原则

- **零重型依赖**：不依赖 Workerman、不依赖 ThinkPHP（性能优先，且规避 Windows 下 `php -S` 的卡死 bug）。
- **原生 socket HTTP 服务器**：`src/Server.php` 用 `stream_socket_server` + `stream_select` 事件循环，支持并发连接与 SSE 长流，跨平台（含 Windows）。
- **binlog 解析用官方 `mysqlbinlog`**：`bin/mysqlbinlog_dump.php` 调用 MySQL 自带 `mysqlbinlog --read-from-remote-server --stop-never`（C 实现、零 PHP 依赖、兼容 MySQL 9 的 `caching_sha2_password`），实时解析后转 JSON 行，由父进程（HTTP 事件循环）经 SSE 推给前端。

## 目录结构

```
agent/
├── bin/
│   ├── agent                 # CLI 入口（serve 启动 HTTP 服务）
│   └── mysqlbinlog_dump.php  # binlog 解析子进程（mysqlbinlog 实时流 → JSON 行）
├── src/
│   ├── Server.php            # 原生 socket HTTP 服务器（事件循环 + SSE）
│   ├── ClientConn.php        # 单连接：HTTP 解析/响应/SSE 写出
│   ├── HttpRequest.php       # HTTP 请求值对象
│   ├── Router.php            # 路由分发 connect/query/dump/close
│   ├── AgentHandler.php      # 会话业务：连接/查询/dump/close（含子进程管道管理）
│   ├── SessionManager.php    # session token → AgentHandler 映射
│   ├── MetaGatherer.php      # 元数据采集（PDO 版，兼容 MySQL 9 的 SHOW BINARY LOG STATUS）
│   ├── Frame.php             # 协议 v2 帧构造（含 SSE 格式）
│   ├── AgentConstants.php    # 协议常量与错误码
│   └── Mysql/PdoConnection.php # 纯 PDO MySQL 连接（跨平台）
└── composer.json             # 仅要求 PHP + pdo_mysql + sockets（无第三方包）
```

## 运行

```bash
# 1. 安装自动加载（仅生成 autoload，无第三方依赖）
composer install

# 2. 启动 HTTP 服务（默认 127.0.0.1:8080）
php bin/agent serve
php bin/agent serve --host 0.0.0.0 --port 8080

# 查看帮助
php bin/agent help
```

## HTTP 路由（协议 v2，与前端 ws.ts 对齐）

| 路由 | 方法 | 说明 |
|------|------|------|
| `/connect` | POST | 测试连接 + 采集元数据，返回 `connected` 帧（含 `session` token）。body: `{host,port,user,password,database,serverId?}` |
| `/query`   | POST | session 复用，只读查询（SELECT/SHOW/DESC/EXPLAIN），返回 `query-result` 帧。body: `{session,sql,database?}` |
| `/dump`    | POST | session 复用，启动 binlog 解析（**SSE 长流**）。body: `{session,binlogFile,binlogPos,startMs?,endMs?}` |
| `/close`   | POST | 销毁会话。body: `{session}` |

### SSE 流（dump）

```
: connected                         # 注释保活
data: {...,"type":"dump-started",...}
data: {...,"type":"binlog-change",...}   # 每个行事件一行，含列名/主键/before/after/xid/时间戳/binlogPos
data: {...,"type":"heartbeat",...}       # 心跳（仅 dump 期间）
data: {...,"type":"binlog-end",...}      # binlog 流结束
```

## 与前端对接

前端 `ws.ts` 已改为 HTTP 客户端（connect/query 走普通请求-响应，dump 走 SSE 读取 `data:` 帧），`session.ts` 与 UI 无需改动。前端 `fetchBaseURL` 指向本服务（如 `http://127.0.0.1:8080`）即可。

## 与旧 agent-workerman 的关系

旧 `agent-workerman` 用 Workerman 的 `AsyncTcpConnection`/`KrowinskiQueryAdapter`。本实现保留其**业务逻辑语义**（元数据字段、只读查询、binlog 事件结构），但底层改为纯 PDO + `mysqlbinlog` 子进程，去掉 Workerman 依赖，规避其在 MySQL 9（`caching_sha2_password`）下 krowinski 握手失败的问题。
