# Task Plan — TypePHP DMS 单二进制 CLI 应用（自起 Web 服务）

## 目标（用户原话）
参考 `D:\git\php\bak\webman_dms`，用 typephp 重新实现一个 **cli 应用**：
1. 打包后（编译成原生二进制）运行时**自己起 web 服务**
2. **关闭二进制后自动退出 web 服务**
3. 通过**二进制同级 `.env` 文件指定端口**（前端监听端口也跟着变）

## 现状盘点（已验证）
- 工作区 `D:\git\php\typephp_dms`（分支 typephp）已从 HEAD 恢复 `agent/` + `frontend/`，其余 372 个文件处于删除状态（旧 webman/parser/agent-workerman 实现，是参考不是目标）。
- `agent/`（HEAD）= 零依赖 PHP HTTP 服务（stream_socket_server + stream_select + SSE + PDO MySQL + mysqlbinlog 子进程），入口 `src/main.php` 已是 TypePHP bin-mode 写法（main()），路由 /ping /connect /query /dump /close 与 webman_dms 对齐。**尚未真正用 tpc 编译过**（无从验证记录）。
- `frontend/`（HEAD）= React 18 + Vite 7 源码，默认 agent 地址硬编码 `http://127.0.0.1:8080`（`lib/ws.ts:19`、`components/AgentConfig.tsx:6`，localStorage key `dms-agent-url`）。
- tpc v0.9.5 可用：`D:\git\php\tpc_v0.9.5_windows_x64\tpc`（已在 PATH）。Node v24 可用（bun 也有）。
- typephp-mcp 不可用 → 用 `tpc --dry` 直接当检查器（skill 规定的回退路径）。

## 关键架构决策
| 决策 | 结论 | 理由 |
|------|------|------|
| 代码基座 | 复用 agent/，不推倒重来 | 架构即"CLI 自起 web 服务"，与目标同构；ub-mode: Make it Work 优先 |
| 关闭二进制→web 服务退出 | 进程内 HTTP 服务（stream_socket_server），无需额外机制 | 进程死=服务死，天然满足 |
| 端口配置 | main() 读二进制同目录 `.env` 的 `PORT=`，命令行 --port 可覆盖 | 明确需求 |
| 前端承载 | 前端 build 产物经 project.yml `embedded-files` 嵌入二进制，Router 增加静态文件路由，同端口同源 | "前端监听端口跟着变"自动成立 |
| 前端默认地址 | 改为 `window.location.origin`（同源），保留 localStorage 覆盖能力 | 嵌入式同源部署 |
| dump（binlog 解析） | 先保 PHP 子进程+mysqlbinlog 语义在解释器下工作；二进制版用隐藏子命令 `__dump_worker` 让二进制自我复用（PHP_BINARY=自身）；若 proc_open 不可用则降级/替代 | 单二进制不能 require 外部 php |
| .env 解析 | 手写极简 parser（KEY=VALUE 行，# 注释） | typephp 无 vlucas/phpdotenv，别引依赖 |

## Phases
- [x] Phase 0 现状盘点 + 参考项目调研（Explore 报告在 findings.md）
- [x] Phase 1 前端：默认地址改同源 + vite build 产物可用（265K dist）
- [x] Phase 2 agent/ TypePHP 编译适配：dry 全绿 → 真实编译链接通过（rung 2+3）
- [x] Phase 3 功能补齐：.env 端口 + 静态文件路由（dist 同目录）+ dump 自我拉起 __dump_worker
- [x] Phase 4 rung 4 端到端验证：.env 端口生效、/ping、/connect（驱动真实 TCP）、前端伺服、遍历拦截、404、关闭进程端口即死、CLI 覆盖 .env
- [x] Phase 5 打包验收：package.bat 组包 22MB → 独立目录 + 最小 PATH 全通过；浏览器视觉验收（SPA 渲染 + "代理已连接"）
- [ ] Phase 6 提交（小步、按 ub-mode 规范）
- [x] Phase 7 macOS 构建（Apple Silicon）：tpc v0.10.0 源码版 + php-builder 私有运行时，产出 Mach-O binlog-agent（2026-11-17）
- [x] Phase 8 dump 24h 窗口故障排查：内存耗尽 255 + stderr 诊断回传，真实 5.7 库复测 176 条变更（2026-11-17）
- [x] Phase 9 库/表过滤下推（2026-11-18）：worker `--db`/`--tables` + AgentHandler 透传 + 前端 `cfg.table` 下发；修复「选了 shengyibao 却显示全实例变更」
- [x] Phase 10 移植缺口审计与补齐（2026-11-18）：对照 git 历史中 webman/agent-workerman 实现，11 项缺口全部补齐并实测；过程中另修 3 个既有缺陷（Windows 管道阻塞拖死全服务 / fwrite 短写丢数据 / dev CLI 无 worker 入口）

## 目标达成对照（用户验收标准 → 证据）
| 要求 | 证据 |
|------|------|
| 用 typephp 重新实现 CLI 应用 | agent/ 全部 src 经 tpc v0.9.5 编译为原生 binlog-agent.exe（Build successful）|
| 打包后运行自己起 web 服务 | 运行 exe 即监听（/ping 200，sapi=embed），前端同端口伺服 |
| 关闭二进制后自动退出 web 服务 | taskkill 后 curl 立即 refused（进程内服务）|
| 二进制同级 .env 指定端口 | .env PORT=9721 → 9721 生效（8080 拒连）；--port 可覆盖 |
| 前端监听端口跟着变 | 前端由二进制同端口伺服 + 默认地址=location.origin（浏览器验收：9721/8080 均渲染 + "代理已连接"）|
| macOS 原生二进制（追加目标） | project.macos.yml + build-macos.sh → binlog-agent（Mach-O arm64 12MB）；24h 窗口 52s 拉到 176 条变更（30 insert + 146 update）+ binlog-end |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
| macOS 下 tpc v0.9.5 缺 host embed 库（Homebrew PHP 只有 cli SAPI） | 1 | 改 tpc v0.10.0 源码版 + `php-builder` 现编私有静态 embed 运行时（~/.typephp 缓存） |
| 静态 PHPX cmake 子构建缺 gmpxx.h / mpfr.h | 1 | 导出 CPATH/CPLUS_INCLUDE_PATH/LIBRARY_PATH=/opt/homebrew（写进 build-macos.sh） |
| dump 24h 窗口 0 变更 + code=255 | 1 | 直连 worker 复现：私有运行时默认 memory_limit=128M，顺序解析 37MB 历史 binlog OOM → worker 内 ini_set 1024M + 窗口前历史行早丢弃 |
| 255 错误帧无原因（stderr 被 Server 事件循环读走） | 1 | AgentHandler.dumpErrBuf 累积 + finishDump 切阻塞读管道残余；worker 函数边界 try/catch（embed 链上 set_exception_handler 无效，实测仍 255）→ 退出码 2 + 可读诊断 |
| 客户端中途断开后整个服务卡死（/ping 无响应、CPU 不涨） | 3 | ①埋点定位到 step3b 的第一次 fread；②实测 `stream_set_blocking` 对 proc_open 管道返回 false、fread 阻塞 14.8s；③HEAD 基线同样卡死 → 确认是重写时丢了 webman 的「文件重定向+增量轮询」；改 runtime/dump_*.out 文件轮询 + 异步 proc_close 回收 |
| `php bin/dump_worker.php` 直跑无任何输出（V2 测试全空） | 1 | 文件只定义函数无 CLI 入口；dev 模式 dump 因此静默 0 变更。补 `PHP_SAPI==='cli' && realpath($argv[0])===__FILE__` 自启动 |
| 哨兵类写在文件顶层 implements SocketInterface → Interface not found fatal | 1 | PHP 早绑定发生在 vendor autoload 之前；改为在 run() 内 autoload 之后条件声明 |
| 用 peer 做会话指纹替换旧会话无效（sessions=3） | 1 | HTTP 模式每次连接临时端口都变；改为前端 sessionStorage 存 prevSession 上报，Router 精确替换 |
| 只 SET GLOBAL binlog_transaction_compression=ON 后哨兵不命中 | 1 | 当前会话仍按 OFF 写事务；补 SET SESSION 后 SHOW BINLOG EVENTS 出现 Transaction_payload → exit 12 → 1012 帧 |
| `cmd /c "tpc-build.bat ..."` 只打印 cmd 横幅、exe 未更新 | 1 | MSYS 把 `/c` 当路径转换；改 `cmd //c` |
| 8092 端口 bind 失败（访问权限不允许） | 1 | Windows 保留端口段；换 8091 |
| 并发 query「12 条只回 1 条」误判 | 1 | 是 `>>` 采集脚本问题；改逐条 -o 采集后 8/8 HTTP 200 且 rows 正确 |
| 多个残留 dev agent 同端口导致测试被污染 | 1 | Windows 允许多进程绑同端口，应答的是旧卡死进程；按 CommandLine 精确 kill 后换端口重测 |

## 风险
- TypePHP 对 stream_socket_server/stream_select/proc_open 的运行时支持未验证（最大风险，Phase 2 尽早触发 rung 4 冒烟）
- 前端 build 产物体积与 embedded-files 机制可行性未验证
- Windows 下 .env 同目录定位：PHP_BINARY 在编译二进制中的取值需实测

## 风险（macOS，2026-11-17 起）
- 产物依赖 Homebrew dylib（gmp/gmpxx/mpfr/oniguruma）：已由 `package-macos.sh` 解决（dylib 随包 + @rpath 改写，目标机无需 Homebrew）；brew 升级 soversion 变化时需同步脚本 DYLIBS 列表
- [已解决 2026-11-18] 大历史 binlog 窗口顺序扫描：探针 v2 两阶段定位已实现（SHOW BINLOG EVENTS 二分边界 + krowinski XID 轻读，margin 256KB 逐级 expand，未命中回退全扫）；实测 37MB + 24h 窗口 45-52s → 14s，事件零遗漏，大文件不再 OOM

## 风险（Windows 移植，2026-11-18 起）
- [已解决] 事件循环里 fread proc_open 管道：Windows 上无法非阻塞（stream_set_blocking 返回 false），实测阻塞 14.8s，一次客户端断开即可永久拖死服务。已改文件重定向 + 增量轮询 + 异步 proc_close；HEAD 基线对照确认是重写时丢失的 webman 设计
- [已解决] SSE/短响应 fwrite 短写丢数据（非阻塞 socket 返回值被忽略）→ ClientConn 出站缓冲 + 每 tick 续写 + 16MB 积压判死
- [已解决] 会话无回收：prevSession 精确替换 + TTL 扫描（DMS_SESSION_TTL 可覆盖，默认 1800s）
- [未处理] vendor/ 被 gitignore 且 composer.json 无 patches 声明：krowinski 的 caching_sha2 AuthMoreData 排空补丁只存在于本机工作树，换机 composer install 会丢（表现为 caching_sha2 账号下 dump 0 事件或 255）。建议固化为 patches/*.patch + composer hook
- [未处理] 前端 ws-agent-url.test.ts 2 例既有失败：断言硬编码 127.0.0.1:8080，而 ws.ts 自 11-12 重写起用 window.location.origin（jsdom 下 localhost:3000）
