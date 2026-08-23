# Spec - 浏览器内 MySQL 数据追踪工具 v1.0

> 生成日期：2026-08-22
> 基于：PRD（用户需求 + 调研报告 report.md）+ 架构文档（四层架构）+ UIUX（design-token 适配）
> 状态：已确认（用户已决策 3 项 OPEN）

---

## 1. 产品定义

- **一句话描述**：用户在浏览器中输入 MySQL 连接信息（经内网 WS 代理桥接），无需安装数据库客户端，即可追踪指定库表在时间范围内的 DML 变更（INSERT/UPDATE/DELETE）并自动生成回滚 SQL 的纯前端工具
- **目标用户**：DBA、后端开发、运维（对标阿里云 DMS 数据追踪的轻量本地替代）
- **核心问题**：误操作（误删/误改）后快速生成回滚脚本；数据变更审计追溯

## 2. MVP 范围（锁定——不在此列表的功能一律不做）

| 优先级 | 功能 | 验收标准摘要 |
|--------|------|-------------|
| P0 | 内网 WS 代理（TypePHP 原生二进制） | 单文件，双击运行，转发 TCP:3306 ↔ WebSocket |
| P0 | 浏览器连接配置 | 输入 host/port/user/password/db，经代理连 MySQL |
| P0 | Binlog 拉取 | 走 MySQL 复制协议（BINLOG_DUMP），ROW 格式 |
| P0 | 变更解析 | 解析 TableMap/WriteRows/UpdateRows/DeleteRows 事件 |
| P0 | 时间范围 + 库表/类型筛选 | 对标 DMS 工单参数（时间/库/表/INSERT/UPDATE/DELETE） |
| P0 | 回滚 SQL 生成 | flashback 算法：WHERE 全列（binlog_row_image=FULL） |
| P0 | 回滚脚本导出 | 下载 .sql / 复制到剪贴板 |
| P1 | 变更明细查看 | 单条记录前后值对比展示 |
| P1 | 连接保存（localStorage，密码可选保存） | 下次免输入 |

## 3. 明确不做（Out-of-Scope — 锁定）

| 不做的功能 | 原因 | 何时考虑 |
|------------|------|----------|
| DDL 追踪（ALTER/CREATE/DROP） | DMS 同样不支持；Row 格式无 DDL 行事件 | 用户反馈后评估 |
| 执行回滚 SQL | 浏览器执行 SQL 风险极高；DMS 也走工单执行 | v2.0 + 审批流 |
| 多数据库支持（PG/Oracle） | MySQL 复制协议专用 | 有需求后评估 |
| 云端部署（CF Workers 桥） | 用户已选内网 WS 代理 | 有公网场景后评估 |
| 实时持续追踪（stop-never） | MVP 定位为"时间范围追踪" | v1.5 |
| GTID 断点续传 | 复杂度高，MVP 用文件+位置 | v1.5 |
| 权限系统/RBAC | 单机工具，无多用户 | 除非转平台产品 |

## 4. 技术架构（锁定 — 含版本锚定）

| 层 | 技术 | 实际版本 | 锁定原因 |
|----|------|----------|----------|
| 前端框架 | React + TypeScript + Vite | React 18+ / Vite 5+ | 生态成熟，Web Worker 友好 |
| 解析核心 | **TypePHP（aot-compiler）PHP 自研解析 → WASM** | PHP 8.4~8.5 → wasm32-wasip2 | 用户选型：一套 PHP 代码双目标 |
| WASM 运行时 | Jco（组件集成） | 与 TypePHP SDK 配套 | TypePHP wasm browser 模式官方方案 |
| 代理端 | **TypePHP 原生二进制（独立单文件）** | 同 SDK | 免 PHP 环境，用户选型 |
| 前端 UI 库 | React + 轻量组件（自写或 headlessui） | 按需 | 锁定一套 SVG 图标库（lucide-react） |
| 打包 | Vite | 5+ | 支持 wasm 资源 |
| 样式 | CSS 变量（design-token 派生）+ 无预处理器 | - | 主题化 |

**AOT 编译 ABI 约束（必须遵守）**：WASM 导出函数仅支持 `bool/int/float/string/void`（int→WIT s64→JS bigint）。数组/对象/引用/生成器为编译错误。→ 接口设计：**binlog 字节流(string)进，JSON 事件流(string)出**，复杂结构在边界做 JSON 序列化。

## 5. API 端点清单（锁定）—— 内部接口契约（非 HTTP）

> 本工具无 HTTP 后端；以下为 WASM 导出函数 + WS 代理消息协议。

### 5.1 WS 代理消息协议（浏览器 ↔ 代理 ↔ MySQL）

| 方向 | 消息 | 载荷 |
|------|------|------|
| 浏览器→代理 | `connect` | { host, port, user, password, database, serverId } |
| 代理→浏览器 | `connected` | { ok, serverVersion, binlogFile, binlogPos } |
| 浏览器→代理 | `binlog-dump` | { binlogFile, binlogPos, slaveFlags } |
| 代理→浏览器 | `binlog-event` | { raw: base64 字节流 } |
| 浏览器→代理 | `query` | { sql }（INFORMATION_SCHEMA 补元数据用，可选） |
| 代理→浏览器 | `query-result` | { columns, rows } |
| 双向 | `error` | { message, code } |
| 代理→浏览器 | `heartbeat` | 保活 |

### 5.2 WASM 导出函数（TypePHP `#[WasmExport]`）

| 函数 | 签名 | 说明 |
|------|------|------|
| `parse_binlog` | `parse_binlog(events_json: string) -> string` | 输入事件数组 JSON（tablemap/rows），输出标准化变更 JSON |
| `generate_rollback` | `generate_rollback(changes_json: string) -> string` | 输入变更 JSON，输出回滚 SQL 文本 |
| `check_binlog_cfg` | `check_binlog_cfg(meta_json: string) -> string` | 校验 ROW/FULL 等前置条件 |

## 6. 数据库表清单（锁定）

> 纯前端工具，无服务端数据库。浏览器侧数据仅存 localStorage：
- `saved_connections`（JSON 数组：连接名/host/port/user/db/是否存密码/密码[可选]）
- `last_trace_config`（最近一次追踪配置）

## 7. 页面清单（锁定）

| 页面 | 路由 | 核心组件 | 对应能力 |
|------|------|----------|----------|
| 连接页 | `/` | 连接表单、已存连接列表 | WS 连接、前置检查 |
| 追踪工单页 | `/trace` | 库/表选择、时间范围、追踪类型勾选 | binlog-dump、解析 |
| 变更列表页 | `/trace/result` | 变更表格、类型筛选、表/列筛选 | 解析结果展示 |
| 明细弹窗 | 内嵌 | 前后值 diff 视图 | 单条明细 |
| 回滚脚本页 | `/rollback` | SQL 预览、复制/下载 | generate_rollback 输出 |

## 8. 设计 Token（锁定）—— 基于 design-token（business-modern 主题适配）

> 来源：design-token skill `tokens/compiled/business-report.json`（DTCG 格式），适配为产品 UI 令牌。
> 图标库锁定：**lucide-react（统一描边 SVG，一套不混用）**，尺寸 16/20/24px。
> 禁止 emoji 图标 / 紫粉渐变 / 硬编码颜色（唯一例外 #fff #000）。

### 8.1 Color（状态语义化）

| Token | 值 | 用途 |
|-------|-----|------|
| --color-primary | #1565C0 | 主品牌蓝（按钮/选中/链接） |
| --color-secondary | #0288D1 | 辅助蓝（次级按钮） |
| --color-accent | #00897B | 强调色（成功/已连接） |
| --color-bg | #F5F7FA | 页面背景 |
| --color-surface | #FFFFFF | 卡片/表格面 |
| --color-text | #212121 | 主文本 |
| --color-textSecondary | #616161 | 次级文本 |
| --color-divider | #E0E0E0 | 分割线 |
| --color-insert | #00897B | INSERT 变更标识（绿） |
| --color-update | #F9A825 | UPDATE 变更标识（琥珀） |
| --color-delete | #E53935 | DELETE 变更标识（红） |
| --color-danger | #E53935 | 错误/回滚危险操作 |
| --color-warn-bg | #FFF8E1 | 参数降级警告背景 |

### 8.2 Typography

| Token | 值 | 用途 |
|-------|-----|------|
| --ff-heading / --ff-body | 微软雅黑, Microsoft YaHei, PingFang SC | 中文字体 |
| --ff-code | Consolas, Source Code Pro, monospace | SQL/事件展示 |
| --ff-data | DIN Next, Arial, sans-serif | 数据/时间数值 |
| --fs-title 20px / --fs-h2 14px / --fs-body 13px / --fs-small 12px / --fs-code 12px | 层级 | 页面级字号 |
| --lh-body 1.6 / --lh-compact 1.2 | 行高 | 正文/表格 |

### 8.3 Spacing & Layout

| Token | 值 |
|-------|-----|
| --spacing-xs 4px / --spacing-sm 8px / --spacing-md 16px / --spacing-lg 24px / --spacing-xl 32px | 间距阶梯 |
| --radius-sm 4px / --radius-md 8px | 圆角 |
| --shadow-card 0 2px 8px rgba(0,0,0,0.08) | 卡片阴影 |
| --layout-max-width 1200px | 内容最大宽度 |

### 8.4 主题模式

- MVP 锁定浅色主题（上述 Token 值）
- 深色主题预留 `[data-theme="dark"]` 变量覆盖层，v1.5 实现

## 9. 验收标准（锁定 — QA 测试时以此为唯一依据）

| 编号 | 功能 | EARS 格式验收标准 | 优先级 |
|------|------|-------------------|--------|
| AC-01 | 代理 | While 用户启动 WS 代理且 MySQL 可达，系统必须建立 WebSocket↔TCP 桥接并回传 connected | P0 |
| AC-02 | 连接 | If 连接信息错误，系统必须返回 error 且 UI 展示明确原因（认证失败/网络不可达） | P0 |
| AC-03 | 前置检查 | If binlog_format 非 ROW，系统必须阻断追踪并提示修改配置，且提供可复制的 my.cnf 修复配置 | P0 |
| AC-04 | 前置检查 | If binlog_row_image 非 FULL，系统必须警告 WHERE 精度降级，且提供动态/静态修复指引 | P1 |
| AC-13 | 权限检测 | When 连接用户缺 SELECT/REPLICATION SLAVE/REPLICATION CLIENT 任一权限，系统必须逐项列出缺失项并给出对应 GRANT 修复语句（可复制） | P0 |
| AC-05 | 追踪 | While 用户选择时间范围+库表+类型并提交，系统必须按 ROW 事件解析并列出变更 | P0 |
| AC-06 | 回滚-INSERT | When 变更含 INSERT 行，回滚脚本必须生成 DELETE ... WHERE 全列=原值 | P0 |
| AC-07 | 回滚-UPDATE | When 变更含 UPDATE 行，回滚脚本必须生成 UPDATE SET 原值 WHERE 新值 | P0 |
| AC-08 | 回滚-DELETE | When 变更含 DELETE 行，回滚脚本必须生成 INSERT INTO ... VALUES(原值) | P0 |
| AC-09 | 事务顺序 | While 生成回滚脚本，多事务必须按提交逆序排列，事务内正序 | P0 |
| AC-10 | 导出 | When 用户点击导出，系统必须下载 .sql 文件且内容与预览一致 | P1 |
| AC-11 | 性能 | While 解析 10 万行以内的变更，浏览器主线程不得阻塞（解析在 Web Worker/WASM） | P1 |
| AC-12 | 类型保真 | When 含 BIGINT/DECIMAL/JSON/DATETIME 列，解析值必须无精度损失（BIGINT 用字符串） | P0 |

## 10. 边界与约束

- 不支持 IE；目标现代浏览器（Chrome/Edge/Firefox/Safari 最新两个大版本）
- MySQL 5.6 及以上；要求 server-id 唯一、binlog_format=ROW、binlog_row_image=FULL
- 连接用户需权限：SELECT、REPLICATION SLAVE、REPLICATION CLIENT
- WASM 单文件体积目标 ≤ 8MB（含 PHP 运行时裁剪）
- 追踪时间范围 ≤ 48 小时（受 Binlog 保留时长限制）
- 浏览器端内存：默认缓冲 ≤ 500MB 变更数据

## 11. 内嵌已知坑

| 坑 | 技术栈指纹 | 根因 | 修法 |
|----|------------|------|------|
| WASM ABI 不支持数组/对象导出 | typephp-wasm-browser | ABI v1 仅标量+string | 边界 JSON 序列化；输入输出全 string |
| MySQL 8.0.20+ binlog_transaction_compression | mysql-8.0.20 | 事务压缩事件无法解码 | 检测并提示关闭该参数 |
| binlog_row_metadata=MINIMAL 时无列名 | mysql-5.7 | TableMap 不含列元数据 | 控制连接查 INFORMATION_SCHEMA 补元数据 |
| 浏览器无 raw TCP | 浏览器沙箱 | net/tls 不可用 | WS 代理桥接（已锁定） |
| INT64 精度 | mysql-bigint | JS Number 精度上限 2^53 | 解析层输出 BIGINT 为字符串（WIT s64 本身是 bigint，天然安全） |

## 12. 端到端验证步骤（Spec 锁定的最后一项）

```bash
# 1. 构建代理（TypePHP 原生二进制）
bin/tpc.php agent/project.yml            # 产出 agent 单文件（或 .exe）

# 2. 构建 WASM 解析核心
bin/tpc.php parser/project.yml           # mode: library, wasm: browser
# 产出 parser.wasm（经 Jco 集成到前端）

# 3. 准备测试库
docker run -d --name mysql57 -p 3307:3306 -e MYSQL_ROOT_PASSWORD=123456 mysql:5.7 \
  --server-id=1 --log-bin=mysql-bin --binlog_format=ROW --binlog_row_image=FULL

# 4. 启动代理
./agent --listen 8080 --target 127.0.0.1:3307

# 5. 浏览器核心成功流
#   连接页填 host=127.0.0.1 port=3307 → connected（成功）
#   追踪工单：库=test 表=tbl 类型=全部 时间=最近1小时 → 提交
#   执行 DML：INSERT → UPDATE → DELETE（手动或脚本）
#   断言：变更列表出 3 条记录，类型正确
#   生成回滚 → 断言 AC-06/07/08/09 全通过

# 6. 关键错误流
#   关闭 binlog 后连接 → AC-03 阻断提示
#   错误密码 → AC-02 error 展示
#   binlog_row_image=MINIMAL → AC-04 降级警告
```

## 13. 变更记录

| 日期 | 变更内容 | 原因 | 影响范围 |
|------|----------|------|----------|
| 2026-08-22 | Spec v1.0 初版 | 用户确认调研结论 + 3 项技术决策 | 全范围 |
| 2026-08-22 | 解析核心改为 TypePHP WASM（原建议 zongji 移植） | 用户决策：复用自家 aot-compiler，一套 PHP 代码双目标 | L2 层全部 |
| 2026-08-22 | 桥接改为内网单文件 WS 代理（原首选 CF Workers） | 用户决策：内网环境优先 | L1 层 |
| 2026-08-22 | 回滚 WHERE 锁定全列 | 用户决策：全列条件 | L3 层 |
| 2026-08-22 | **增强：连接后权限检测 + binlog 格式检测 + 修复引导** | 用户需求：不符合时引导如何设置权限（GRANT）与调整格式（my.cnf） | §5.2 check_binlog_cfg 输出扩展 fix 引导字段；连接页/追踪工单页引导 UI（2 页）；AC-03/AC-04 升级为带修复指引 |

### 13.1 变更详情：权限/Binlog 检测 + 修复引导（v1.1）

**check_binlog_cfg 输出扩展**（errors[]/warnings[] 每项新增 `fix` 字段）：

| 检查项 | 不符合时 | fix 引导内容 |
|--------|----------|-------------|
| log_bin 开启 | error 1003 | my.cnf 配置块：`[mysqld] server-id=1 log_bin=/var/log/mysql/mysql-bin.log` + 重启提示 |
| binlog_format=ROW | error 1003 | `binlog_format=ROW` 追加至 my.cnf + 重启提示 |
| binlog_row_image=FULL | warning 1004 | `binlog_row_image=FULL` 追加 + 动态设置 `SET GLOBAL binlog_row_image=FULL` |
| 权限 SELECT | error 1004 | GRANT 语句：`GRANT SELECT, REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'user'@'host';` + FLUSH PRIVILEGES |
| 权限 REPLICATION SLAVE/CLIENT | error 1004 | 同上 GRANT 语句 |
| server-id 冲突 | error 1009 | 提示更换 serverId 或停用其他从机 |

**前端引导 UI**：连接页测试连接后 → 检查区展示错误/警告列表，每项可展开"如何修复"（GRANT 语句/配置块以 --ff-code 展示 + 一键复制）。
