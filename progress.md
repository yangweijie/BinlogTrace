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
