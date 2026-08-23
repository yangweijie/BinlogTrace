# 浏览器内 MySQL 数据追踪工具 — 技术调研报告

> 版本：v1.0 | 生成日期：2026-08-22 | 团队：MVP 开发专家团（大湾区靓仔统筹）
> 项目总监（大湾区靓仔）汇总 | PM（许清楚）开源方案评估 | 架构师（高见远）技术可行性验证

---

## 0. 执行摘要（TL;DR）

**结论先行，三条硬事实：**

1. **"纯浏览器 + 零服务端直连 MySQL"在 TCP 层不可行**。浏览器沙箱禁止 WASM/JS 建立 OS 级 TCP Socket（webpack 的 `node-libs-browser` 把 `net`/`tls` 映射为 null），Emscripten 的 socket 模拟本质是把 TCP 代理成 WebSocket，仍需要额外端点。WebTransport 也救不了——MySQL 协议基于 TCP 而非 QUIC，且 Safari 26.4+ 才支持。**WASM 解决"解析/计算"，不解决"网络连接"。**

2. **不需要重复造轮子**。解析核心复用 `@vlasky/zongji`（MIT、活跃维护、纯 JS 可剥离移植）；回滚 SQL 生成复用 `binlog2sql` flashback 算法（纯 TS 重写，零依赖）。市场上**不存在**"浏览器内 Binlog 追踪 + 回滚生成"的完整开源产品——这是空白市场，也是机会。

3. **最接近用户目标的方案 = 最小桥接面**。二选一：Cloudflare Workers + Hyperdrive（凭证安全、无本地服务端，最贴需求）或 单文件 WebSocket TCP 代理（内网兜底）。解析与回滚全部在浏览器端执行。

**MVP 最小路径**（详见 §4）：约 6 个里程碑，从"能连上"到"能出回滚脚本"，1 个全职开发 2-3 周可跑通核心链路。

---

## 1. 调研范围与方法

### 1.1 产品对标：阿里云 DMS 数据追踪（功能规格）

| 维度 | 规格 |
|------|------|
| 前提 | MySQL 5.6+、已开启 Binlog |
| 追踪范围 | DML（INSERT / UPDATE / DELETE），**不支持 DDL** |
| 时间范围 | 自由操作实例 30 分钟；稳定变更/安全协同 48 小时（受 Binlog 保留时长限制，单工单≤48h） |
| 输出 | 回滚脚本（可下载/批量导出）+ 按库/表/列/追踪类型筛选 + 单条明细查看 |
| 流程 | 建工单 → 审批 → 日志下载解析 → 勾选变更记录 → 导出回滚脚本 → 数据变更工单执行 |
| 回滚规则 | INSERT 类→回滚为 DELETE；UPDATE 类→回滚为 UPDATE（值互换）；DELETE 类→回滚为 INSERT |

### 1.2 调研方法

- 网络检索 10+ 轮（WebSearch/WebFetch），覆盖：WASM 网络能力边界、Binlog 解析库生态（JS/Rust/Java/Python/Go）、浏览器桥接方案、现成产品
- PM 负责开源方案全景与对比矩阵；架构师负责技术可行性验证与分层架构；总监做一致性检查与裁决
- 关键一手证据均已核实来源（GitHub 仓库、crates.io、Cloudflare 官方文档、MDN）

---

## 2. 开源方案全景评估

### 2.1 对比矩阵（10 项候选，PM 产出）

| # | 方案 | 语言 | 定位 | 浏览器可用性 | 维护活跃度 | 成熟度 | Star 量级 | 回滚SQL生成 |
|---|------|------|------|-------------|-----------|--------|-----------|-------------|
| 1 | binlog2sql | Python | 回滚SQL生成标杆 | 不可直接用（需服务端） | 原版停滞，有活跃fork | 高（线上验证） | ~3.5k | 支持（flashback） |
| 2 | MyFlash | C++ | 回滚工具 | 不可用 | 停滞 | 中 | ~2.4k | 支持 |
| 3 | my2sql | Go | 回滚/解析 | 不可用 | 活跃 | 中 | ~1.5k | 支持 |
| 4 | Yearning | Go | SQL审核+回滚平台 | 不可用（服务端） | 活跃 | 高 | ~8.5-9k | 支持（AGPL，注意许可） |
| 5 | @vlasky/zongji | Node.js 纯JS | Binlog CDC | **解析核心可剥离移植** | 活跃（2026-07 v0.9.0） | 高（MySQL 5.7/8.0/8.4+MariaDB） | ~2k | 不支持（需自研） |
| 6 | @powersync/mysql-zongji | Node.js 纯JS | Binlog CDC（fork） | 解析核心可剥离 | 活跃 | 中 | 较小 | 不支持 |
| 7 | mysql-binlog-connector-java | Java | Binlog 解析 | 不可用（需JVM） | 活跃 | 高 | ~2.7k | 不支持 |
| 8 | mysql-binlog-connector-rust | Rust | Binlog 解析 | **可编 wasm32** | 活跃（2026-04） | 中高 | ~600 | 不支持（有离线解析） |
| 9 | rust-us/mysql-cdc-rs | Rust | Binlog 解析 | 可编 wasm32 | 新/活跃 | 低 | 较小 | 不支持 |
| 10 | binlog2sql-web | Python(Django) | binlog2sql Web壳 | 不可用（服务端） | 停滞 | 低 | 较小 | 支持（间接） |

### 2.2 PM 结论（三条）

1. **解析核心**：首选 `apecloud/mysql-binlog-connector-rust`（WASM 编译路径，支持 5.6/5.7/8.0、GTID、ZSTD 压缩 binlog、纯离线文件解析，Apache/MIT 双许可）；备选 `zongji` 纯 JS 解析层（零编译链）。
2. **产品功能参考**：Yearning（工单/回滚流程/RBAC，**仅参考交互，AGPL 不可抄代码**）+ 阿里云 DMS 产品形态。
3. **浏览器直连不可行佐证**：`mysql_js_driver` 需要 `chrome.sockets.tcp`（仅 Chrome 扩展环境）——纯 Web 页面无路可走，必须"桥"。

### 2.3 总监一致性检查与裁决

| 检查项 | 结果 |
|--------|------|
| PM vs 架构师解析核心选型分歧 | 裁决：**MVP 用 zongji 纯 TS**（零编译链、MIT、两天可跑通）；Rust/WASM 列为 Phase 2 性能优化（大文件离线解析） |
| 桥方案一致性 | 双方均确认"最小桥接面"方向，无冲突 |
| 许可合规 | 采纳 PM 红线：AGPL 只参考不抄，MIT/Apache 可复用 |
| 浏览器直连不可行 | 双方证据链一致，无冲突 |

---

## 3. 关键技术可行性结论（架构师产出）

### 3.1 已验证事实

1. **zongji 解析核心可移植**：`lib/` 目录下 `binlog_event.js` / `rows_event.js` / `code_map.js` / `datetime_decode.js` / `json_decode.js` 为纯 JS、零 C++ 原生依赖，仅依赖连接层 + big-integer + Buffer（可 polyfill）。解析逻辑与连接逻辑可剥离。
2. **mysql2 无法在浏览器运行**：依赖 Node `net`/`tls`/`Buffer`，浏览器无 raw TCP。连接层必须桥接。
3. **WebTransport 也不可行**：MySQL 复制协议是 TCP 私有协议，非 QUIC；且 Safari 26.4+ 才支持，跨浏览器不可依赖。
4. **`binlog_row_metadata=FULL`（MySQL 8.0+/MariaDB 10.5+）时，列名/符号性/字符集全在 binlog 流内**，无需查 INFORMATION_SCHEMA——大幅降低浏览器端复杂度。MySQL 5.7 及以下需 TableMap + 控制连接查 INFORMATION_SCHEMA。

### 3.2 核心架构结论

```
"纯浏览器零服务端直连 MySQL" = 不可行（TCP 硬约束）
        ↓
"最小桥接面" = 唯一可行路径
        ↓
桥只管字节流转发，解析+回滚全在浏览器端
```

### 3.3 桥接方案对比（L1 连接层）

| 方案 | 用户安装 | 凭证安全 | 适用场景 | 复杂度 |
|------|---------|---------|---------|--------|
| Cloudflare Workers + Hyperdrive + mysql2(disableEval:true) | 需 CF 账号（无本地安装） | 高（凭证在 Worker 侧，前端不可见） | 公网/云上 MySQL | 中 |
| WebSocket TCP 代理（单文件 agent，~100 行） | 需下载并运行一个单文件 | 低（凭证经代理明文） | 内网 MySQL / 离线环境 | 低 |
| 原生 TCP | 无 | - | **不可行** | - |
| WebTransport + 自定义 MySQL-over-QUIC 桥 | 需 HTTP/3 桥 | 中 | 实验性，不推荐 MVP | 高 |

---

## 4. 最小可行实现路径（MVP）

### 4.1 四层架构（架构师产出，已过门禁）

```
┌─────────────────────────────────────────────────┐
│ L4 前端 UI 层（React 18 + TS + Vite）             │
│   · 连接表单 / 追踪任务 / 变更列表 / 回滚脚本导出  │
│   · Web Worker 运行解析，避免 UI 阻塞             │
│   · SVG 图标库（锁定一套）+ CSS 变量 Token        │
├─────────────────────────────────────────────────┤
│ L3 回滚脚本生成层（纯 TS，零依赖）                │
│   · binlog2sql flashback 算法移植                 │
│   · DELETE→INSERT / UPDATE 值互换 / INSERT→DELETE │
│   · 事务逆序输出 + WHERE 条件构造                 │
├─────────────────────────────────────────────────┤
│ L2 Binlog 拉取解析层                             │
│   · MVP：zongji lib 纯 JS 解析层移植（+Buffer polyfill）│
│   · Phase 2：mysql-binlog-connector-rust 编 wasm32│
│   · 事件流：TableMap / WriteRows / UpdateRows / DeleteRows │
├─────────────────────────────────────────────────┤
│ L1 数据库连接层（最小桥接面）                     │
│   · 首选：Cloudflare Workers + Hyperdrive 拉取    │
│     binlog 字节流，经 WebSocket/HTTP 转发给浏览器 │
│   · 兜底：单文件 WebSocket TCP 代理（内网）       │
└─────────────────────────────────────────────────┘
```

### 4.2 推荐依赖组合（MVP）

| 层 | 依赖 | 版本要点 | 许可 |
|----|------|---------|------|
| 前端 | react + typescript + vite | React 18+ / Vite 5+ | MIT |
| 解析核心 | @vlasky/zongji（仅 lib 解析层） | v0.9.0（2026-07） | MIT |
| Buffer | buffer polyfill | 浏览器打包自动注入 | MIT |
| 大整数 | big-integer（zongji 依赖） | - | MIT |
| 桥接（首选） | Cloudflare Workers + Hyperdrive + mysql2 | mysql2 ≥3.13.0，`disableEval:true` | MIT |
| 桥接（兜底） | 自写 WS TCP 代理（Node ~100行 或 Python） | 无外部依赖 | 自研 |

### 4.3 里程碑（从零到可用）

| 里程碑 | 目标 | 关键交付 | 预计 |
|--------|------|---------|------|
| M1 | 桥接打通 | CF Worker（或 WS 代理）能拉取 binlog 字节流并转发到浏览器 | 3-4 天 |
| M2 | 解析跑通 | zongji 解析层在浏览器 Web Worker 内出结构化事件（TableMap/WriteRows/UpdateRows/DeleteRows） | 3-4 天 |
| M3 | 回滚生成 | flashback 算法纯 TS 实现：单表单事务回滚 SQL 正确 | 2-3 天 |
| M4 | 时间范围+筛选 | 按时间/库/表/类型过滤（对标 DMS 工单参数） | 2 天 |
| M5 | UI 完成 | 连接表单→追踪任务→变更列表→回滚脚本导出 | 3-4 天 |
| M6 | 端到端验证 | Docker MySQL(5.7+8.0) 双版本 + 自动化断言回滚正确性 | 2 天 |

**合计约 2-3 周（1 人全职）跑通核心链路。**

### 4.4 端到端验证步骤（Spec 锁定）

```bash
# 1. 准备测试库（Docker）
docker run -d --name mysql57 -p 3307:3306 -e MYSQL_ROOT_PASSWORD=123456 mysql:5.7 \
  --server-id=1 --log-bin=mysql-bin --binlog_format=ROW --binlog_row_image=FULL

# 2. 前置检查（浏览器端完成）
SHOW MASTER STATUS;              # 获取 binlog 文件+位置
SELECT @@binlog_format;          # 必须为 ROW
SELECT @@log_bin;                # 必须为 ON

# 3. 核心成功流
#   执行 DML：INSERT → UPDATE → DELETE
#   浏览器追踪 → 应出 3 条变更记录
#   生成回滚脚本 → 断言：
#   INSERT 行 → DELETE ... WHERE 全列 = 原值;
#   UPDATE 行 → UPDATE SET 原值 WHERE 新值;
#   DELETE 行 → INSERT INTO ... VALUES(原值);

# 4. 关键错误流
#   Binlog 未开启 → 明确报错提示
#   无 REPLICATION SLAVE 权限 → 明确报错
#   binlog_row_image=MINIMAL → 回滚 WHERE 退化为主键（警告提示）
```

---

## 5. 风险清单与替代方案

| # | 风险 | 等级 | 缓解 |
|---|------|------|------|
| 1 | 浏览器无 raw TCP，"零服务端"需求无法 100% 满足 | **高** | 用 CF Workers + Hyperdrive 做到"无本地服务端"；对内网用户提供单文件 WS 代理（一次下载） |
| 2 | MySQL 5.7 及以下无 `binlog_row_metadata=FULL`，需查 INFORMATION_SCHEMA 补列元数据 | 中 | 控制连接查询（zongji 已实现该路径）；ALTER 后元数据过期风险按表刷新 |
| 3 | 大 Binlog / 高频库性能：JS 解析成为瓶颈 | 中 | 解析放 Web Worker；Phase 2 换 Rust WASM 解析核心 |
| 4 | binlog_transaction_compression（8.0.20+）zongji 不支持 | 中 | 提示用户关闭该参数，或 Phase 2 用支持 ZSTD 的 Rust crate |
| 5 | 凭证经桥传输的安全风险 | 高 | CF 方案凭证不出前端；WS 代理方案提示用户仅内网/可信网络使用 |
| 6 | 回滚 WHERE 条件精度依赖 binlog_row_image=FULL | 中 | 检测该参数，非 FULL 时降级主键 WHERE 并提示 |
| 7 | 许可合规 | 低 | 只复用 MIT（zongji）/Apache（rust crate）；Yearning AGPL 仅参考交互 |
| 8 | Safari 26.4 以下无 WebTransport | 低 | MVP 不用 WebTransport，统一 WebSocket/HTTP |

**替代方案（若用户改变约束）**：
- 若接受"轻量本地服务端"：直接套 binlog2sql-web 思路（Python + Django），一周可交付，但违背用户"无服务端"初衷
- 若接受 Electron/Tauri 桌面端：回归原生 TCP，所有桥接复杂度消失，但不再是纯浏览器产品
- 若仅需"追查+展示"不需回滚：可用 Debezium/canal 等成熟 CDC 服务端，浏览器只做消费端

---

## 6. 附录

### 6.1 关键参考资料

- binlog2sql: https://github.com/danfengcao/binlog2sql （flashback 算法标杆）
- @vlasky/zongji: https://github.com/vlasky/zongji （MIT 纯 JS 解析核心，v0.9.0）
- mysql-binlog-connector-rust: https://github.com/apecloud/mysql-binlog-connector-rust （Rust/WASM 路径）
- Yearning: https://github.com/cookieY/Yearning （产品交互参考，AGPL）
- binlog2sql-web: https://github.com/hanzhongzi/binlog2sql-web （Web 化需求验证）
- Cloudflare Hyperdrive + MySQL: https://developers.cloudflare.com/hyperdrive/examples/connect-to-mysql
- 阿里云 DMS 数据追踪: https://help.aliyun.com/knowledge_detail/311369.html
- MDN WebTransport: https://developer.mozilla.org/en-US/docs/Web/API/WebTransport_API

### 6.2 团队交付记录

- PM 对比矩阵 + 3 条结论（§2）
- 架构师可行性验证 + 四层架构（§3、§4）
- 总监一致性检查、选型裁决、OPEN-DECISIONS 登记（docs/decisions/OPEN-DECISIONS.md）
