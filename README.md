# DMS Agent — TypePHP 单包 CLI 应用（自起 Web 服务）

用 TypePHP 编译成 **Windows 原生二进制** 的 MySQL binlog 数据追踪代理（参考 webman_dms 重实现）。
运行二进制即启动自带的 HTTP 服务：同端口伺服 **前端 SPA** 与 **API**；关闭二进制，Web 服务随之退出；
监听端口由**二进制同级 `.env`** 指定，前端（同源默认地址）自动跟随。

## 源码布局（前后端一体，项目根即应用）

```
.
├── project.yml             # TypePHP 构建配置（mode: bin，输出 binlog-agent.exe）
├── composer.json/.lock     # 前后端一体依赖（krowinski 等，psr-4 DmsAgent\ → src/）
├── src/                    # 后端（DmsAgent\*，经 tpc 编译为原生代码）
├── bin/dump_worker.php     # dump 子进程业务逻辑（普通 PHP，随包分发，不经编译）
├── frontend/               # 前端源码（React 18 + Vite 7 + TS）
├── dist/                   # 前端构建产物（vite outDir → 根 dist/，gitignored）
├── vendor/                 # composer 依赖（gitignored，随包分发）
├── php.ini                 # 运行时扩展加载（embed SAPI 读二进制同目录 ini）
├── .env.example            # 端口配置模板（PORT / HOST）
├── tpc-build.bat           # vcvars64 + PHPRC 隔离封装的 tpc 包装
└── package.bat             # 组包脚本 → release/dms-agent/（单目录可分发）
```

## 打包产物布局（`package.bat` 生成，约 22MB）

```
dms-agent/
├── binlog-agent.exe        # TypePHP 编译的主程序（HTTP 服务 + API + dump 编排）
├── php8ts.dll / phpx.dll / gmp-10.dll / mpfr-6.dll / libmpdec*.dll   # 运行时
├── php.ini                 # package.bat 生成，extension_dir 指向本包 ext/
├── ext/
│   ├── php_pdo_mysql.dll   # MySQL 驱动（动态扩展）
│   └── php_sockets.dll     # sockets（非内置，dump worker 需要）
├── dist/                   # 前端 SPA 构建产物（React + Vite）
├── vendor/                 # krowinski/php-mysql-replication（binlog 解析，普通 PHP）
├── bin/
│   └── dump_worker.php     # dump 子进程业务逻辑（普通 PHP，由主程序经 ZendVM 执行）
└── .env                    # 端口配置（见 .env.example）
```

## 快速开始

```bat
:: 1. 配置端口（可选，默认 8080）
set PORT=9721 > .env   或编辑 .env： PORT=9721

:: 2. 运行
binlog-agent.exe serve

:: 3. 浏览器打开 http://127.0.0.1:<PORT>/ —— 前端与 API 同端口
```

关闭二进制（Ctrl+C / 关窗口 / taskkill）即 Web 服务退出——服务运行在进程内（stream_socket_server
+ stream_select 事件循环），没有需要单独清理的子服务。

命令行 `--port` / `--host` 可临时覆盖 `.env`。

## HTTP API（协议 v2，与 webman_dms / 前端 ws.ts 对齐）

| 路由 | 方法 | 说明 |
|------|------|------|
| `GET /` `GET /assets/*` | GET | 前端静态文件（dist/），同端口同源 |
| `GET /ping` | GET | 健康检查（前端 5s 探测，显示「代理已连接」） |
| `POST /connect` | POST | 测试连接 + 采集元数据，回 `connected` 帧（含 session token） |
| `POST /query` | POST | session 复用，只读查询（SELECT/SHOW/DESC/EXPLAIN），回 `query-result` |
| `POST /dump` | POST | session 复用，启动 binlog 解析（**SSE 长流**：dump-started → binlog-change* → heartbeat → binlog-end） |
| `POST /close` | POST | 销毁会话 |

帧格式 `{v:2, id, type, ts, payload}`；错误帧 `type:"error"` + `payload:{code,message}`
（错误码表见 `src/AgentConstants.php`）。

## 架构要点（为什么这样设计）

- **主程序（TypePHP 编译）**：`src/` 全部经 tpc 编译为原生代码。HTTP 服务、协议路由、
  会话管理、PDO 查询在二进制内直接运行。
- **dump 子进程自我复用**：binlog 解析需要 krowinski 库（匿名类/闭包等动态特性不适合
  AOT 编译），因此 `handleDump` 以 **自身二进制** 拉起子进程（`binlog-agent.exe __dump_worker ...`，
  隐藏子命令），子进程内的 embed SAPI 经 ZendVM 执行磁盘上的 `vendor/ + bin/dump_worker.php`
  （普通 PHP，保留已验证的 krowinski 解析逻辑）。JSON 行经 stdout 管道由事件循环转发 SSE。
- **路径事实**（tpc v0.9.5 实测）：`PHP_BINARY` 指向 tpc.exe、`__DIR__` 是编译期源码路径，
  均不可用于运行期定位；可靠的是 `$argv[0]`（本二进制的真实路径）——`src/Runtime.php`
  统一维护 binDir，`.env`/`dist/`/`vendor/` 都以它为基准。
- **扩展加载**：embed SAPI 会读 **二进制同目录的 php.ini**。bcmath 已静态内建；
  `pdo_mysql`、`sockets` 为动态扩展随包分发（见 `php.ini` / `package.bat`）。

## 从源码构建

前置：tpc v0.9.5（PATH）、Node 18+、composer（tpc 发行版自带 php.exe）、MSVC BuildTools
（vcvars64.bat，脚本已封装）。

```bash
# 1) 前端（frontend/ 源码 → 根 dist/，vite outDir 已指向 ../dist）
cd frontend && npm install && npm run build && cd ..

# 2) 后端依赖（dump worker 用的 krowinski 等）
composer install

# 3) 编译主程序（tpc-build.bat 封装 vcvars64 + PHPRC 隔离，输出根 binlog-agent.exe）
cmd /c "tpc-build.bat . <abs-path>\project.yml --no-progress --build-dir <abs-path>\build"

# 4) 组包 + 独立验收（自包含单目录）
cmd /c "package.bat <abs-path>\release\dms-agent"
```

> 注意：**不要在项目根直接运行 tpc**——根目录的 `php.ini`（为编译产物准备的）会被
> tpc 自身的 PHP 从 cwd 加载并破坏其模块启动；`tpc-build.bat` 已切到无 php.ini 的临时目录执行。

## 已验证 / 待验证

已验证（rung 4 实测，含真实 MySQL 8.0.36 环境）：
- 二进制自起 Web 服务、关闭即退出
- `.env` 指定端口、命令行覆盖、前端同端口伺服（含浏览器视觉验收）
- `/ping`、`/connect`（pdo_mysql 驱动加载 + 真实 TCP 连接 + 元数据/权限采集）
- `/dump` 全链路 SSE：`dump-started → binlog-change* → heartbeat → binlog-end`
  - 实时变更推送（insert/update/delete 行镜像、主键、xid、binlog 位置）
  - **超 24 小时窗口过滤**（30h 起点自动定位 binlog 文件，历史变更精确召回）
  - 窄窗口时间戳过滤
  - SSE 客户端中途断开后服务端存活、子进程自动清理、同会话可复用
- 回滚脚本生成（逆操作映射、xid 分组、事务包裹、PK WHERE、独立回滚开关）
- 独立目录 + 最小 PATH 自包含运行（`__dump_worker` 自我拉起 → vendor/krowinski 解析）
