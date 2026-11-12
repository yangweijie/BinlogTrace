# Findings — TypePHP DMS

## 参考项目 webman_dms（Explore agent 报告要点，2026-11-12）
- 架构：React SPA（public/ 已构建，无源码）+ webman HTTP 后端（单进程 8080）。后端不做回滚 SQL 生成（前端 Web Worker 纯 JS generateRollback），只做：MySQL 代理查询、元数据采集、krowinski 子进程 binlog 解析 → SSE 推流。
- API 表（协议 v2 帧 `{v,id,type,ts,payload}`）：
  - GET/POST /ping → `{ok:true,...}`
  - POST /connect `{host,port,user,password,database,connectTimeoutMs,serverId?}` → 单帧 connected（meta+session 32hex token，内存 Map）
  - POST /query `{sql,database?,session}` → query-result `{columns:[{name,type}],rows}`（SELECT|SHOW 白名单，独立查询连接，按库重建）
  - POST /binlog-dump 别名 /dump `{binlogFile,binlogPos,startMs,endMs,session}` → SSE：dump-started → binlog-change* → heartbeat(15s) → binlog-end{exitCode:0} | error
  - POST /close `{session}` → `{ok:true}`
  - binlog-change payload: `{kind:"insert|update|delete",schema,table,columns[],primaryKeys[],before,after,xid,timestamp,binlogFile,binlogPos}`
- 错误码：1001 AUTH_FAILED 1002 NETWORK_UNREACHABLE 1003 BINLOG_DISABLED 1004 PERMISSION_DENIED 1005 PARSE_ERROR 1006 PROXY_NOT_READY 1007 TIMEOUT 1008 INVALID_PARAM 1009 SERVER_ID_CONFLICT 1010 PROTOCOL_ERROR 1011 BINLOG_POSITION_INVALID 1012 TRANSACTION_COMPRESSED 1013 META_MISSING 1099 INTERNAL_ERROR
- MetaGatherer 7 条 SQL：SHOW BINARY LOG STATUS / SHOW MASTER STATUS（二选一）、@@server_id、@@binlog_format、@@binlog_row_image、@@version、SHOW GRANTS（解析 SELECT/REPLICATION SLAVE/REPLICATION CLIENT；ALL PRIVILEGES 全有）
- binlog 解析：krowinski/php-mysql-replication v11.1.0 子进程（bin/krowinski_dump.php 385 行）：订阅 WRITE/UPDATE/DELETE_ROWS_EVENT **V1+V2**+XID+ROTATE+HEARTBEAT_LOG_EVENT；XID 事件给 pending 行赋 xid；startTs 起点文件探测（SHOW BINARY LOGS 从新到旧，每文件短命 factory 8s 上限）；心跳兜底退出 time()>endTs+2。密码走 env DMS_MYSQL_PASSWORD。Windows 下 proc_open 管道非阻塞无效 → stdout 重定向文件 + 0.1s Timer 轮询。
- 前端：React 18 + Vite、hash 路由（/ 连接页、/trace、/rollback）、fetch POST JSON 帧、dump 用 fetch+getReader 手动分 SSE（POST 无法用 EventSource）、agent 地址 localStorage `dms-agent-url` 默认 http://127.0.0.1:8080。
- generateRollback（前端纯 JS）：null→NULL、数字原样、X'..'原样、字符串 '...'（\→\\、'→''）；insert→DELETE、update→UPDATE(仅变更列)、delete→INSERT(before 镜像)；WHERE PK 优先；按 xid 分组、组内最大 ts 降序；START TRANSACTION 包裹可选。

## 本仓库 HEAD 现状
- `agent/` = 上述功能的零依赖实现：stream_socket_server + stream_select 事件循环（Server.php 208 行），ClientConn HTTP 解析/SSE，Router，AgentHandler（PDO 连接/查询/dump 子进程 proc_open 管道非阻塞——PHP 8 Windows 下 OK），MetaGatherer，PdoConnection（pdo_mysql），mysqlbinlog_dump.php（**调用外部 mysqlbinlog --read-from-remote-server**，非 krowinski）。入口 src/main.php（TypePHP bin mode：main(argc,argv)）。
- project.yml: `name: binlog-agent, mode: bin, output: binlog-agent, sources: [src], ext-deps: [pdo pdo_mysql]`（注意 ext-deps 一行两个值，格式待验证）
- `frontend/` = React 源码（vite 7, bun.lock + package-lock），默认 URL 硬编码 8080（ws.ts:19, AgentConfig.tsx:6）
- git 工作区：仅恢复 agent/ frontend/，其余删除态是旧实现（webman/ agent-workerman/ parser/），不作目标。

## 环境事实
- tpc v0.9.5 Windows x64 在 PATH（/d/git/php/tpc_v0.9.5_windows_x64/tpc）；该目录含 SDK/、ext/、build/、examples/ 等
- Node v24.10.0 + npm + bun 可用（PhpWebStudy 环境）
- typephp-mcp **不可用**（工具列表无 mcp__typephp__*）→ 遵循 skill 回退：读 skill references + tpc --dry 直接验证
- 关键 skill 提醒：check_code/tpc --dry 只到 rung 1；undefined symbol/运行时错不报；必须真编译+运行才算数

## 待验证问题（开放）
- [x] stream_socket_server/stream_select/proc_open 在 TypePHP 二进制中可用（smoke rung-4 实测 ✅）
- [x] PHP_BINARY 在编译二进制中 = **tpc.exe（编译器路径），不可用**；`$argv[0]` = 真实二进制全路径 ✅（.env 定位用它）；__DIR__ = 编译期源码路径，不可用于运行期
- [ ] project.yml embedded-files 机制与取用 API（frontend dist 嵌入）
- [x] ext-deps 写法（未用——见下）
- [x] tpc 支持 declare(strict_types)/heredoc/match（11 文件 dry 全绿）
- [x] **TP-AOT-021（pdo_mysql 缺失）解法**：embed SAPI 会读**二进制同目录的 php.ini**（跨 cwd 实测 ✅）。部署布局：`binary + php.ini + php_pdo_mysql.dll(+依赖DLL)` 同目录。`dl()` 不可用（false），php.ini 自动加载才是正道。PDO 抛真实连接错误证明驱动工作。
- [x] dump 子进程：**自我拉起方案验证通过**——`binlog-agent.exe __dump_worker ...` 子进程内 embed SAPI 经 ZendVM require 磁盘 vendor/ + bin/dump_worker.php，krowinski 类真实发起 socket 连接（无 MySQL 时报连接拒绝=预期）。SSE 全链路（binlog-change 流）待真实 MySQL 环境联调。
- [x] 环境故障记录：**tpc 手动调用必须先 source vcvars64.bat**（否则 C1083 malloc.h/cstring）。已建 `agent/tpc-build.bat` 包装（注意：bat 内不能有括号块 + 中文注释须 ASCII）

## Phase 3-5 新增实测结论（2026-11-12）
- **embedded-files 在 tpc v0.9.5 Windows 发行版不可用**：缺 `phpx/thirdparty/opcache/opcode_unserialize_85.c`（及 header），公开 GitHub 仓库无 phpx 源码可补 → 放弃嵌入，改随包目录（dist/ + vendor/ + bin/dump_worker.php）。
- **`$GLOBALS` 编译不支持**（C2065 未声明的标识符）。
- **cwd 的 php.ini 会破坏 tpc 自身**：agent/php.ini（为编译产物准备）被 tpc 的 PHP CLI 从 cwd 加载 → typephp_tpc 模块启动失败；PHPRC 置空无效（Windows CLI 仍搜 cwd）→ 构建脚本须切到无 php.ini 的 cwd（tpc-build.bat 已做）。
- **ext-deps 陷阱**：project.yml 里 `ext-deps: [- "pdo pdo_mysql"]` 整串当模块名 → 启动 fatal "required module not found"；本项目不需要声明（\PDO 类运行时解析）→ 已删除。
- **扩展可用性（embed 运行时，PHP 8.5.11 ZTS）**：bcmath **内置**（无 dll 文件但 bcadd 可用）；sockets **非内置**（必须随包 php_sockets.dll，否则 krowinski socket_create 未定义）；pdo_mysql 动态（php_pdo_mysql.dll，仅依赖 php8ts）。
- **embed SAPI 运行期 require 磁盘普通 PHP 完全可用**（探针实证：类加载、方法调用正常）→ dump worker 用普通 PHP 随包，绕开 AOT 限制且保留 krowinski 原生语义。
- **二进制 DLL 依赖闭包**（deplister 实测）：exe→php8ts/phx/libmpdec++；phpx→php8ts/gmp-10/**mpfr-6**/libmpdec*。注意 mpfr 文件名是 mpfr-6.dll。
- **embedded 原始文件可经编译期 `__DIR__` 绝对路径读取**（opcode 表按路径键取）——但部署机无此路径，不可依赖；仅作机制备忘。

## 编译兼容修复记录（agent/src，均已 dry 通过）
1. Server.php: `$id` 一变量两类型（int 赋值→foreach key Var）→ 循环键改名 `$cid`
2. AgentHandler.php: 属性默认值引用他类常量 `= AgentConstants::CONNECT_TIMEOUT_MS` → 移到构造器
3. ClientConn.php: respond() 3参被传4参（/ping 传 content-type）→ 增加第4参 `string $contentType`
4. Router.php: `$_SERVER['REQUEST_TIME_FLOAT']` bin 模式不可靠 → Server::$startedAt 静态时间戳
5. tpc v0.9.5 链接的运行时 = PHP 8.5.11 ZTS embed SAPI
