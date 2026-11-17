# Progress Log

## Session 2026-11-12

- [07:5x] 加载 ub-mode / planning-with-files / typephp-project 三个 skill
- [07:5x] 检查 git 状态：分支 typephp，工作区 372 文件删除态，HEAD 含完整旧实现
- [07:5x] Explore agent 深调研 webman_dms 参考项目（报告全文见 findings.md）
- [07:5x] 恢复 agent/ + frontend/ 至工作区；确认 tpc v0.9.5、Node v24 可用
- [07:5x] 读 Server.php / main.php / Router.php / AgentHandler.php / ClientConn.php
- [07:5x] 写 task_plan.md / findings.md / progress.md
- [08:0x] tpc --dry 基线：4 处编译兼容修复（$id 类型冲突/属性默认值他类常量/respond 4参/$_SERVER）→ 目录 dry 全绿
- [08:1x] rung-4 冒烟（smoke/）：stream socket/select/proc_open ✅；pdo_mysql ❌（TP-AOT-021 仍在）；PHP_BINARY=tpc.exe、__DIR__=源码路径、argv[0]=二进制路径
- [08:2x] **TP-AOT-021 解决**：embed SAPI 读二进制同目录 php.ini → php_pdo_mysql.dll 自动加载，驱动真实工作（跨 cwd 验证）
- [08:2x] vcvars64 环境故障定位 + tpc-build.bat 封装（ASCII 注释、无括号块）
- [08:3x] agent 全量编译链接成功（15 文件）；ext-deps 字符串陷阱 → 删除；/ping //connect 二进制内打通
- [08:4x] 前端：DEFAULT_BASE/DEFAULT_URL 改同源 + vite build ✅
- [08:5x] embedded-files 实证不可用（缺 thirdparty 源）→ 架构改随包目录；探针验证 embed 内 require 磁盘 PHP 可行
- [08:6x] Phase 3 实现：Runtime/EnvLoader/main(.env+隐藏子命令)/DumpWorker/Router 静态路由/AgentHandler 自我拉起/bin/dump_worker.php（krowinski 逻辑移植）
- [08:7x] 新编译器限制：$GLOBALS 不支持；cwd php.ini 破坏 tpc（构建切 cwd 解决）
- [08:8x] rung 4 e2e：.env 端口 9721 ✅、前端伺服 ✅、遍历拦截 ✅、404 ✅、/connect 驱动真实 TCP ✅、__dump_worker 自拉起+krowinski ✅、taskkill 后端口即死 ✅、--port 覆盖 ✅
- [08:9x] 打包：package.bat 组包 22MB（修正 mpfr-6.dll 名字 + 补 php_sockets.dll）→ 独立目录最小 PATH 全通过
- [09:0x] 浏览器视觉验收：打包二进制伺服的 SPA 渲染正常，顶栏"代理已连接"（同源 ping 成功）；包内 .env 改 9721 前端跟随 ✅
- [09:0x] README 重写、规划文件更新、SDK 目录清理
- 下一步：提交

## 测试结果
| 验证项 | 结果 |
|--------|------|
| tpc 真实编译+链接（rung 2+3）| Build successful: binlog-agent.exe |
| 二进制自起 web 服务 | /ping 200 JSON（sapi=embed, uptime, sessions）|
| .env 端口（dev+package）| 9721 生效，8080 拒连 |
| 命令行 --port 覆盖 | 9777 生效 |
| 前端静态伺服 | / 200 text/html；/assets/*.js 200 text/javascript |
| 路径遍历拦截 | /assets/../.env → 400 |
| /connect（无 MySQL）| 规范错误帧 1001 + pdo_mysql=true 诊断 |
| dump 自拉起 | 子进程二进制 → vendor+krowinski → 真实 socket 尝试 |
| 关闭即退出 | taskkill 后 9721 立即 refused |
| 独立包最小 PATH | System32-only PATH 下全部功能正常 |
| 浏览器验收 | SPA 渲染 + "代理已连接"（同源）|

## Session 2026-11-17（macOS 移植 + dump 排障 + 重启复测）

- [15:4x] 目标：Mac 下编译主程序。现状：构建脚本全是 Windows .bat，本机 PATH 无 tpc
- [15:5x] 调查：本机多个 composer 版 tpc（各 vendor/bin/tpc.php）+ typephp-compiler 源码仓库（v0.10.0）；~/.typephp 已有 PHP 8.5.11 私有运行时缓存；gmp/mpfr 已装 Homebrew；同类项目（typephp-dbtools）有 Mach-O arm64 编译成功先例（project.macos.yml 范例）
- [15:5x] tpc v0.9.5（gpui2）dry：缺 host embed 库；v0.10.0 + --php-builder：私有运行时 PHP 编完，静态 PHPX 缺 gmpxx.h/mpfr.h → 补 CPATH=/opt/homebrew → dry 全绿（14 C++ 文件）
- [16:0x] 全量编译成功：binlog-agent（Mach-O arm64 12MB）；新增 project.macos.yml（php-builder 扩展列全）+ build-macos.sh
- [16:0x] 冒烟：/ping 200（sapi=embed, 8.5.11）、/ 200、/assets 200、遍历 400、/connect 1001 + pdo_mysql=true、__dump_worker 自拉起 krowinski（无 MySQL 时预期连接拒绝）、kill 后端口即释放
- [16:1x] php.ini 换 mac 版（Windows 版备份 php.ini.windows）；README 补 macOS 构建章节
- [16:1x] **用户报障**：拉 103.115.42.75:3306 最近 24h 变更，数据库有新增/更新却拉不到，SSE 只见心跳 → code=255
- [16:2x] 排查：只读查库事实（5.7.43，ROW/FULL，6 文件，37MB）；直连 worker 复现 → 不带 start-ts 立即吐 insert（链路正常）；带 start-ts → OOM fatal（RowEvent.php:493）→ 255
- [16:4x] 修复（详见 findings.md）：worker ini_set 1024M + collectRow 早丢弃历史行 + 函数边界 try/catch（embed 链 set_exception_handler 无效）；AgentHandler/Server stderr 累积回传 + finishDump 阻塞读残余；mac php.ini 1G；重编译
- [16:5x] 端到端验证（9721）：错误帧带原因（code=2 + 未捕获异常...）；24h 窗口 37s 吐 4 条 change ✓
- [17:2x] 重启 8080 服务（旧 62955 → 新 13876，新二进制，服务保持运行）+ 完整复测：24h 窗口 52s → **176 条 binlog-change（30 insert + 146 update）+ binlog-end**，0 fatal；无残留 worker
- 下一步：提交（Phase 6，含 macOS 构建 + dump 修复，当前 5 改 3 新未提交）；可选：package-macos.sh 组包、XID 轻量探针定位起点；提醒用户轮换连接密码（已明文出现在会话）

## 测试结果（macOS，2026-11-17）
| 验证项 | 结果 |
|--------|------|
| tpc v0.10.0 全量编译（Mac） | Build successful: binlog-agent（Mach-O arm64 12MB）|
| /ping | 200 JSON（sapi=embed, PHP 8.5.11）|
| 前端静态 / /assets | 200；遍历 400 |
| /connect（真实 5.7 库） | connected + 元数据（5.7.43，SHOW MASTER STATUS 回退生效）|
| dump 24h 窗口（修复后） | 52s → 176 条 binlog-change（30 insert + 146 update）+ binlog-end，0 fatal |
| 错误场景（不存在 binlogFile） | code=2 + `dump_worker: 未捕获异常: Could not find first log file name...`（不再裸 255）|
| 重启服务 | 旧 62955 → 新 13876，uptime 重置确认新二进制；关闭即释放端口 |

## Session 2026-11-18（macOS 自包含发行包）

- [00:0x] 目标：写 package-macos.sh（dylib 随包分发）
- [00:0x] 依赖分析（otool -L）：主二进制 → gmp/gmpxx/mpfr/onig 4 个 Homebrew dylib；gmpxx/mpfr → gmp（Cellar/opt 绝对路径）；libc++/libresolv/libz/libSystem 为系统库不随包
- [00:0x] 写 package-macos.sh：组包（二进制+php.ini+dist+vendor+bin/dump_worker.php+.env）+ dylib 拷入 lib/ + install_name @rpath 化 + 清构建机 rpath + @loader_path/lib + 内置 /ping 验收
- [00:0x] 首跑 bug：fix_ref 用 `grep -o '[^ ]*'` 取旧路径，otool 行首 TAB 被带进 `install_name_tool -change` → 静默无效（rc=0）；改整行 trim 后匹配
- [00:1x] 重跑全绿：主二进制与 lib/ 内部引用全 @rpath；/ping 验收轮询化（embed 启动 >1s）
- [00:1x] 强化验收：`env -i` 无环境（无 PATH/HOME/DYLD_*）从 /tmp 启动包内二进制 → /ping OK；/connect 真实连 103.115.42.75:3306 成功（pdo_mysql=true，1045 为预期无密码）
- [00:1x] README 补「自包含发行包」节；.gitignore 加 /release-mac/；task_plan/findings 同步
- 下一步：提交（Phase 6，含 package-macos.sh + release-mac 忽略项）；可选 XID 轻量探针优化历史窗口扫描速度（已在 00:2x 会话完成，见下）

## 测试结果（macOS 发行包，2026-11-18）
| 验证项 | 结果 |
|--------|------|
| 包内容 | release-mac/dms-agent（19MB）：binlog-agent + lib/（4 dylib）+ php.ini + dist/ + vendor/ + bin/ + .env |
| install_name | 主二进制与 lib/ 内部引用全部 @rpath，构建机 rpath 已清，@loader_path/lib 已加 |
| 干净环境验收 | env -i 下 /ping OK（自包含成立） |
| /connect（env -i，真实库） | pdo 链路通（pdo_mysql=true），无密码时 1045 规范错误帧 |

## Session 2026-11-18（00:1x 进度同步，planning-with-files）

- 核对：规划文件自 00:11 组包同步后无新代码改动（最新提交仍 6b1bbd6；未提交 = 5 改 3 新 + 规划文件 + .gitignore）
- 状态变化：**8080 服务不在运行**（无 binlog-agent 进程，此前 PID 13876 已退出）——需要时 `cd 项目根 && ./binlog-agent serve`，或直接解压组包运行
- 修正：上节 00 点档会话的时间戳原误记为 14-15 点，已按文件 mtime（00:09-00:11）更正
- 下一步：提交（Phase 6）；可选 XID 轻量探针优化历史窗口扫描速度（已在 00:2x 会话完成）

## Session 2026-11-18（00:2x XID 轻量探针 v2，历史窗口提速）

- 目标：实现两阶段定位，拉历史窗口不再顺序扫整段历史（此前「暂开放」项）
- [00:0x] v1 尝试：krowinski `withEventsOnly(XID/ROTATE)` 从文件头顺序转移 → 超时未命中。根本缺陷：目标在文件尾部，COM_BINLOG_DUMP 仍要转移全量历史，miss 成本翻倍（实测 122s > 原 52s），废弃
- [00:1x] 诊断 5.7 事实：SHOW BINLOG EVENTS 无时间戳列、Query 的 Info 无 SET TIMESTAMP 前缀、FROM 需事件边界（error 1220）、LIMIT offset,count 可用 → v1b（SHOW 分页找 SET TIMESTAMP）注定不命中，废弃
- [00:2x] v2 落地：P_now（MASTER/BINARY LOG STATUS 两段 try）+ SHOW LIMIT n,1 指数/二分边界（margin 256KB→2MB→16MB→整文件）+ krowinski XID 轻读跳窗口首提交点（expand 防首 XID 漏事件）+ 未命中回退全扫
- [00:2x] 实测：37MB + 24h 窗口总耗时 **14s**（旧全扫 45-52s）；176 条事件（30 insert + 146 update）零遗漏；first>=startTs ✓；不存在文件回归 exit 2 + 可读诊断 ✓
- 同步：README「历史窗口排障」补探针 v2 节；task_plan 风险项、findings 更新
- 下一步：提交（Phase 6，含探针 v2 + 组包脚本 + 这两日全部改动）

## 测试结果（XID 探针 v2，2026-11-18）
| 验证项 | 结果 |
|--------|------|
| 24h 窗口总耗时 | 14s（旧全扫 45-52s，v1 未命中 122s）|
| 事件完整性 | 176/176（30 insert + 146 update），零遗漏 |
| 时间边界 | first timestamp >= startTs ✓ |
| 探针日志 | margin=256KB 边界 → XID 探针命中 pos=37419984（跳过历史段）|
| 错误路径回归 | 不存在 binlogFile → exit 2 + `Could not find first log file name...`（不再裸 255）|
| SSE 端到端（/connect + /dump 24h，服务 8080） | 首条 change @ **8.7s**（旧全扫 45-52s），binlog-end @ 13.8s；176 条零遗漏；心跳 0 次；服务日志见探针命中 pos=37419984（margin=256KB）|
| 接口注意 | /dump 需先 POST /connect（payload.session），带 session 复用，否则 error 1006「会话不存在或已过期」|
| 实时 dump 回归（不带 startMs） | 无探针日志（count=0，探针仅在 startMs>0 激活）；远端库造 1 insert + 1 delete（jay_music.dms_rt_check，用完 DROP），两帧 change <1s 到达且行镜像完整（columns/primaryKeys/before/after）|
