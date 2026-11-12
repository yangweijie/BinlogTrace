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

## 目标达成对照（用户验收标准 → 证据）
| 要求 | 证据 |
|------|------|
| 用 typephp 重新实现 CLI 应用 | agent/ 全部 src 经 tpc v0.9.5 编译为原生 binlog-agent.exe（Build successful）|
| 打包后运行自己起 web 服务 | 运行 exe 即监听（/ping 200，sapi=embed），前端同端口伺服 |
| 关闭二进制后自动退出 web 服务 | taskkill 后 curl 立即 refused（进程内服务）|
| 二进制同级 .env 指定端口 | .env PORT=9721 → 9721 生效（8080 拒连）；--port 可覆盖 |
| 前端监听端口跟着变 | 前端由二进制同端口伺服 + 默认地址=location.origin（浏览器验收：9721/8080 均渲染 + "代理已连接"）|

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
| (待记录) | | |

## 风险
- TypePHP 对 stream_socket_server/stream_select/proc_open 的运行时支持未验证（最大风险，Phase 2 尽早触发 rung 4 冒烟）
- 前端 build 产物体积与 embedded-files 机制可行性未验证
- Windows 下 .env 同目录定位：PHP_BINARY 在编译二进制中的取值需实测
