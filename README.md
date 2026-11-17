# DMS Agent — TypePHP 单包 CLI 应用（自起 Web 服务）

用 TypePHP 编译成 **Windows / macOS 原生二进制** 的 MySQL binlog 数据追踪代理（参考 webman_dms 重实现）。
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

## 在 macOS 上构建（Apple Silicon / arm64）

Windows 的 `tpc-build.bat` / `package.bat` 在 macOS 不适用，用 `project.macos.yml` + `build-macos.sh`：

```bash
brew install gmp mpfr oniguruma             # 静态 PHPX / mbstring 链接的动态库
cd frontend && npm install && npm run build && cd ..
composer install
./build-macos.sh                           # → ./binlog-agent（Mach-O arm64，约 12MB）
```

要点：

- 编译器用 TypePHP **v0.10.0 源码版**（可用 `TPC=/path/to/tpc.php` 覆盖）。v0.9.5 的
  `PhpBuilderSource` 会把 `core` 伪扩展拿去查 PECL 而 404，php-builder 阶段直接中止。
- 宿主 Homebrew PHP 只有 cli SAPI（无 libphp），`php-builder` 从 php-src 现编一份静态 embed
  运行时（缓存 `~/.typephp`，二次构建复用）。扩展在 `project.macos.yml` 显式列全：
  `pdo / pdo_mysql / sockets / bcmath / filter / mbstring / ctype`——Windows 靠 php.ini 动态加载
  `.dll`，macOS 是静态编进 `libphp.a`。
- 构建脚本导出 `CPATH` / `CPLUS_INCLUDE_PATH` / `LIBRARY_PATH` 指向 `/opt/homebrew`：
  cmake 子构建编静态 PHPX 时不会继承 tpc 的 Homebrew 路径，否则 `gmpxx.h` / `mpfr.h` 找不到。
- `php.ini` 已换成 macOS 版（原 Windows 版备份为 `php.ini.windows`）；产物以自身所在目录为基准
  定位 `.env` / `dist/` / `vendor/` / `bin/dump_worker.php`。
- 产物链接 `/opt/homebrew/opt/{gmp,gmpxx,mpfr,oniguruma}` 的 dylib。分发到未装这些库的 Mac
  时用 `package-macos.sh` 组自包含包（dylib 随包，见下节）。

运行方式与 Windows 一致：在二进制同目录 `.env` 写 `PORT=9721`，或直接
`./binlog-agent serve --port 9721`，浏览器打开 `http://127.0.0.1:9721/`。

macOS 实测（2026-11-17，arm64 / PHP 8.5.11 embed）：`/ping` 200（sapi=embed）、前端静态伺服、
路径遍历 400、`/connect` 规范错误帧且 `pdo_mysql` 可用、`__dump_worker` 自我拉起并加载
vendor/krowinski、关闭进程后端口立即释放。

### 历史窗口（startMs>0）排障

拉「最近 N 小时」时，起点探测会把起点定位到 binlog 文件头（pos=4）以保证不漏，
worker 需要顺序扫过该文件里的历史事件。binlog 文件较大时注意：

- worker 内已 `memory_limit=1024M`（macOS 的 `php.ini` 也设了 1G）。默认 128M 会在
  krowinski 的 `RowEvent` 解析途中耗尽 → fatal → 子进程退出 255，前端只见心跳、零变更。
- 窗口起点之前的历史行在 `collectRow` 里直接丢弃，不进待输出缓冲，内存不再随历史量增长。
- 子进程异常由函数边界兜底捕获（退出码 2 + 可读原因），原因随 SSE `error` 帧回传，
  不再只有 `code=255`。注意 embed 调用链上 `set_exception_handler` 不生效，必须在 PHP
  函数内 try/catch。
- 优化前大文件首次拉取会有几十秒只有心跳（顺序扫描历史段），两阶段定位（下条）已解决。
- **两阶段定位（探针 v2，2026-11-18）**：拉历史窗口时 worker 先用
  `SHOW BINLOG EVENTS LIMIT n,1` 二分找「≈ P_now − margin」的事件边界（margin 256KB 起跳、
  未覆盖时 expand 逐级扩大），再 krowinski 轻量读（只订 XID/ROTATE、行事件不解析、内存平稳）
  跳到窗口内首个提交点，阶段 2 从该事务起点读、跳过历史段；未命中自动回退「文件头全扫」。
  实测 37MB 历史文件 + 24h 窗口：worker 总耗时 45-52s → **~14s**，事件零遗漏、大文件不再 OOM；
  SSE 端到端（POST /connect → POST /dump，带 startMs）：**首条 `binlog-change` 帧 8.7s 到达**
  （旧逻辑 45-52s，其间只见心跳），176/176 条、心跳 0 次、`binlog-end` 13.8s。

### 自包含发行包（package-macos.sh）

```bash
./package-macos.sh                # → release-mac/dms-agent（约 19MB，含 lib/ 内 4 个 dylib）
zip -r dms-agent-macos.zip release-mac/dms-agent
```

- 把 4 个 Homebrew dylib 拷进包内 `lib/`，install_name 全部改写为 `@rpath`，主二进制加
  `@loader_path/lib` rpath 并清掉构建机绝对 rpath。**目标机只要是 arm64 Mac 即可运行，
  无需 Homebrew / PHP / 编译器**（脚本自带 /ping 验收；另经 `env -i` 干净环境验证：
  /ping + /connect 的 pdo_mysql 链路正常）。
- 包布局与 Windows 包一致（二进制 + php.ini + dist/ + vendor/ + bin/dump_worker.php + .env），
  解压即用。
- 脚本 `DYLIBS` 列表里的文件名是 ABI 版本号（gmp 6.3 / mpfr 4.2 / oniguruma 6.2）；
  brew 升级导致 soversion 变化时需同步更新。

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
