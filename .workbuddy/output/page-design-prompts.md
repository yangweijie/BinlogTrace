# 页面设计提示词 — 浏览器内 MySQL 数据追踪工具（Phase 2 交付）

> 交付：UI/UX 设计师（颜好看）→ 前端实现
> 前置产物：design-tokens.css（唯一样式入口）/ spec-v1.0.md §7 页面清单
> 图标库锁定：lucide-react（统一描边 SVG）；尺寸 --icon-size-inline 16 / --icon-size-button 20 / --icon-size-standalone 24；禁止 emoji 图标
> 寄存器：Product（工具 UI）— 克制色彩，主色每屏 ≤2 处大面使用；无营销 Hero，首屏即核心功能

## 通用约定（全 5 页强制）
- 全部颜色/间距/字体引用 CSS 变量，禁止裸 hex（唯一例外 #fff #000）
- 间距只用 --spacing-xs 4 / sm 8 / md 16 / lg 24 / xl 32；圆角 --radius-sm 4 / --radius-md 8
- 卡片 = --color-surface + --radius-md + --shadow-card；页面背景 --color-background
- 文本：--color-text 主 / --color-textSecondary 次 / 分割线 --color-divider
- 字号：--fs-title 20 / --fs-h2 14 / --fs-body 13 / --fs-small 12 / --fs-code 12
- 数值/时间用 --ff-data（DIN Next）；SQL/值/表名用 --ff-code（Consolas）
- 可访问性：focus-visible 2px --color-primary 环；全键盘可达；`prefers-reduced-motion` 下禁用旋转/微光动画
- 按钮状态矩阵：Default/Hover（同色加深 8% 可用 color-mix）/Active/Disabled（opacity .45）/Loading（内置 Loader2）/Error

---

## 页面 1：连接页 `/`

### 布局（--layout-max-width 1200px，12 列栅格）
```
┌ TopBar: [Logo+产品名] ················· [● WS代理状态] ┐
├────────────────────────────────────────────────────────┤
│ 左 7 列：新建连接卡片                  │ 右 5 列：已存连接列表 │
│  连接名称 [__________]                 │ ┌ 卡片: name        ┐ │
│  主机 [__________]  端口 [____]        │ │ host:3306 / db    │ │
│  用户 [__________]  密码 [____][eye]   │ │ [连接] [删除]     │ │
│  [x] 保存到本地                       │ └──────────────────┘ │
│  [测试连接]  [连接并追踪]               │ 空态引导文案          │
└────────────────────────────────────────────────────────┘
```

### 组件与状态
- **TopBar**：品牌名（--ff-heading --fs-title）；代理状态徽标：connected → 绿点 + 文案"代理已连接"（--color-accent）；未连接 → 灰点（--color-textSecondary）+ "代理未连接"
- **连接表单**（--color-surface 卡片）：
  - 字段：连接名称 / host / port（默认 3306，--ff-data）/ user / password（lucide Eye/EyeOff 切换可见性）/ database（可选）
  - 校验（blur+提交）：host/user 必填、port 1–65535；错误 → 输入框 1px --color-danger 边框 + 字段下方 8px 红字（--color-danger --fs-small）
  - 按钮：[测试连接] 次级（1px --color-secondary 边框 + --color-secondary 文字）；[连接并追踪] 主按钮（--color-primary 底 + #fff 文字）
  - Loading：按钮内 Loader2 16px 旋转，双按钮禁用；Success：测试通过 → 按钮文字"连接正常" + lucide Check（--color-accent）
- **已存连接列表**（--color-surface 卡片）：每项连接名（--fs-body --fw-emphasis）+ host:port/db（--fs-small --color-textSecondary）+ [连接]（--color-primary 文字）/ [删除]（--color-textSecondary，hover --color-danger）；来源 localStorage `saved_connections`

### 关键交互
1. 校验失败聚焦首个错误字段
2. "保存到本地"勾选 → 写 localStorage；密码仅勾选时存储
3. 测试连接 → WS `connect` 消息 → 代理回 `connected`/`error`，结果内联展示（卡片内，非全局 toast）
4. 点击已存连接 → 自动测试连接，成功跳转 /trace

### 空态/错误文案
- 代理不可达：`无法连接 WS 代理（ws://127.0.0.1:8080）。请确认已双击运行 agent 单文件，且端口未被占用。`
- 认证失败（AC-02）：`连接失败：认证失败（Access denied for user 'root'@'127.0.0.1'）。请检查用户名与密码。`
- 已存列表空：`还没有保存的连接。左侧填写表单并勾选「保存到本地」后，下次从这里一键进入。`

---

## 页面 2：追踪工单页 `/trace`

### 布局
```
┌ TopBar: [← 返回连接]  当前连接: prod-db (10.0.0.8:3306) ┐
├────────────────────────────────────────────────────────┤
│  工单卡片（居中，max 720px，--color-surface）             │
│  数据库 [select ▾]                                      │
│  数据表 [select ▾]                                      │
│  时间范围 [2026-08-22 13:00] ~ [14:00]  [近1h|6h|24h]   │
│  追踪类型 ( )INSERT ( )UPDATE ( )DELETE                 │
│  ┌ 前置检查结果区（warnBg 或 danger 边框）             ┐  │
│  └────────────────────────────────────────────────────┘ │
│  [开始追踪]（primary 全宽）                              │
└────────────────────────────────────────────────────────┘
```

### 组件与状态
- **连接上下文条**：连接名/host/port（--ff-code --fs-small --color-textSecondary）；未连接由路由守卫拦截回 /
- **库/表 Select**（级联）：选项来自 INFORMATION_SCHEMA 查询；加载态骨架行；默认表"全部"
- **时间范围**：两个 datetime-local（--ff-data）；快捷标签 最近1小时/6小时/24小时（选中 --color-primary 文字）；校验：end≤start 或跨度>48h（Spec 约束）→ 红字 `时间范围无效：结束时间必须晚于开始时间，且跨度不超过 48 小时。`
- **追踪类型**：三 checkbox，前色点 lucide Circle 填充三态色：INSERT --color-insert / UPDATE --color-update / DELETE --color-delete；默认全选；全不选 → 红字 `请至少勾选一种追踪类型。`
- **前置检查区**：提交时调 check_binlog_cfg；阻断（AC-03）→ 1px --color-danger 边框 + --color-danger 文字 + 提交按钮禁用；警告（AC-04）→ --color-warnBg 底 + --color-update 文字，仍可提交
- **[开始追踪]**：primary 全宽；Loading → Loader2 + "正在拉取 Binlog…" + 进度条（--color-primary）

### 关键交互
1. 库选择 → 级联刷新表列表
2. 前置检查阻断时置灰提交，警告时降级放行
3. 提交成功后跳 /trace/result，URL 携带工单参数（可刷新恢复）

### 空态/错误文案
- 无库权限：`当前账号无权限查看数据库列表，请确认已授予 SELECT 权限。`
- 阻断（AC-03）：`Binlog 配置不符合追踪要求：binlog_format 必须为 ROW（当前为 STATEMENT）。请修改 my.cnf 后重启 MySQL 再重试。`
- 无 binlog（AC-03 变体）：`未检测到 Binlog，请确认 MySQL 已开启 log_bin 并设置了 server-id。`
- 降级（AC-04）：`binlog_row_image=MINIMAL，回滚 WHERE 条件将缺少部分列，精度降级。建议设为 FULL 以获得完整回滚条件。`

---

## 页面 3：变更列表页 `/trace/result`

### 布局
```
┌ TopBar: [← 返回工单]  prod-db.tbl_order · 13:00–14:00 · 1,284 条 ┐
├──────────────────────────────────────────────────────────────────┤
│ 筛选栏: [全部|INSERT|UPDATE|DELETE]  表[▾] 列[▾]    匹配 1,284 条 │
├──────────────────────────────────────────────────────────────────┤
│ 变更表格（sticky thead）                                          │
│  # │ 类型     │ 库.表    │ 操作时间       │ 主键    │ 变更列 │ 操作 │
│ ▍1 │ [INSERT] │ db.tbl   │ 14:02:11.345   │ id=1024 │  3    │ 明细 │
│ ▍2 │ [UPDATE] │ db.tbl   │ 14:02:11.400   │ id=1024 │  2    │ 明细 │
├──────────────────────────────────────────────────────────────────┤
│ 分页 100/页  [1][2][3]…                                          │
│ 右下浮动: [生成回滚脚本]（primary，有变更时出现）                   │
└──────────────────────────────────────────────────────────────────┘
```
（▍ 表示行首 3px 三态色条）

### 三态色方案（重点）
- **行首色条**：每行最左 3px 竖条（宽 --spacing-xs，圆角 --radius-sm），颜色=变更类型：INSERT --color-insert / UPDATE --color-update / DELETE --color-delete
- **类型标签**：小 pill —— 1px 边框 + 文字同色（--color-insert/update/delete），背景 --color-surface（不新增背景色，保持锁定 Token 内）
- 行 hover：背景 --color-background；选中行：2px --color-primary 左侧条（配合键盘 ↑↓ 导航）

### 组件与状态
- **工单摘要条**：库.表（--ff-code）+ 时间范围（--ff-data）+ 总条数（--ff-data --color-primary，全屏唯一 1 处主色强调）
- **类型筛选**：4 pill（全部+三态）；激活态填充三态色 + #fff 文字；未激活 1px --color-divider 边框 + --color-textSecondary；切换后表格过滤 + 匹配计数更新 + 表/列下拉选项按剩余类型联动
- **变更表格**：列：# / 类型 / 库.表（--ff-code）/ 操作时间（--ff-data）/ 主键（--ff-code）/ 变更列数（--ff-data）/ 操作（"明细"文字链接 --color-primary）
  - Loading：10 行骨架屏（--color-background 占位块微光，尊重 reduced-motion）
  - 空态（见下）；Error：顶部红条（--color-danger）
- **[生成回滚脚本]**：右下浮动 primary；点击 → generate_rollback → 跳 /rollback

### 关键交互
1. 类型/表/列筛选组合过滤；筛选状态写入 URL（可分享/刷新保持）
2. 整行可点（Enter 同样触发）→ 打开明细弹窗
3. 大列表分页 100/页；解析在 Web Worker，主线程不阻塞（AC-11）

### 空态/错误文案
- 无变更：`指定时间范围内未检测到 DML 变更。可能原因：该表无写入、Binlog 已过期清理、或时间范围过窄。可返回工单页扩大范围重试。`
- 解析失败：`解析 Binlog 失败：事件流中断（可能原因：binlog_transaction_compression 已开启、或 Binlog 文件被轮转）。请检查 MySQL 配置后重新追踪。`

---

## 页面 4：明细弹窗（内嵌于 /trace/result）

### 布局
```
┌──────────── 变更明细 · 单条记录 ─────────────┐
│ [UPDATE] db.tbl_order · 14:02:11.400    [X] │
│ 事务 1287 · 主键 id=1024 · bin.000023:4412  │
├────────────────────────────────────────────┤
│ 列名        │ 旧值               │ 新值      │
│ status      │ 1                  │ 2        │ ← 变更行高亮
│ pay_amount  │ 199.00             │ 299.00   │ ← 变更行高亮
│ updated_at  │ 2026-08-22 14:01:00│ 14:02:11 │ ← 变更行高亮
│ remark      │ 已支付             │ 已支付    │ （未变，常规）
├────────────────────────────────────────────┤
│ [生成该行回滚]（次级）                        │
└────────────────────────────────────────────┘
```

### 组件与状态
- **Modal**：遮罩 rgba(0,0,0,.45)（黑色 45% 透明，属 #000 例外）；卡片 --color-surface + --radius-md + --shadow-card；头部类型标签 + 库.表（--ff-code）+ 关闭 lucide X（hover --color-danger）；Esc 关闭、点击遮罩关闭
- **元信息条**：事务号 / 主键 / Binlog 位置（--ff-code --fs-small --color-textSecondary）
- **diff 表**（重点）：
  - 表头：列名 | 旧值 | 新值（--fs-h2 --fw-emphasis）；单元格 --fs-body --ff-code，行间 --color-divider
  - **UPDATE**：仅变更列整行背景 --color-warnBg + 新值文字 --color-update + 旧值 --color-text；未变列常规展示
  - **INSERT**：旧值列显示虚线 `—`（--color-textSecondary），新值列常规；整行不加高亮（类型标签已表达）
  - **DELETE**：新值列显示 `—`，旧值列常规
  - NULL：斜体 `NULL` --color-textSecondary；BIGINT/DECIMAL/JSON 以字符串展示（AC-12 精度保真）；超长文本截断 + [展开]（lucide ChevronDown）
- **底部按钮**：[生成该行回滚] 次级 → 跳 /rollback 并预选该行

### 空态/错误文案
- 明细加载失败：`明细加载失败：行数据缺失（可能因 Binlog 行已轮转）。请重新追踪。`

---

## 页面 5：回滚脚本页 `/rollback`

### 布局
```
┌ TopBar: [← 返回变更列表]  回滚脚本 · 3 事务 / 1,284 条变更 ┐
├──────────────────────────────────────────────────────────┤
│ 工具条: [复制全部](primary) [下载 .sql](次级)   行数 3,201 │
├──────────────────────────────────────────────────────────┤
│ SQL 预览（--ff-code --fs-code，行号栏 + 语法高亮）         │
│  1 /* 事务 1287 · 提交于 14:02:11 · 逆序回滚 */            │
│  2 DELETE FROM db.tbl_order                               │
│  3 WHERE id=1024 AND status=2 AND pay_amount=299.00;      │
│  4 UPDATE db.tbl_order SET status=1                       │
│  5 WHERE id=1024 AND status=2;                            │
├──────────────────────────────────────────────────────────┤
│ [警告] 执行提示卡（--color-warnBg）                             │
└──────────────────────────────────────────────────────────┘
```

### SQL 语法高亮方案（重点，仅用锁定 Token，禁止新增颜色）
| 词法 | Token | 修饰 |
|------|-------|------|
| 回滚语句主词（DELETE/INSERT/UPDATE，即对原 INSERT/DELETE/UPDATE 的逆向） | --color-delete / --color-insert / --color-update | --fw-emphasis |
| 其余关键字（FROM/WHERE/SET/VALUES/INTO/AND/OR） | --color-primary | 常规 |
| 字符串字面量 '...' | --color-accent | 常规 |
| 数字 | --color-secondary | --ff-data |
| NULL | --color-textSecondary | 斜体 |
| 注释 /* ... */ | --color-textSecondary | 斜体 |
| 表名/列名/标识符 | --color-text | 常规 |
| 行号栏 | --color-textSecondary | 背景 --color-background，右 1px --color-divider |

实现：轻量 tokenizer 按上表输出 span 映射；逐行渲染防卡顿。

### 组件与状态
- **工具条**：[复制全部] primary（lucide Copy 20）；[下载 .sql] 次级（lucide Download 20）；复制成功 → Toast `已复制 3,201 行 SQL 到剪贴板`（2s 自动消失）
- **SQL 预览**：行号栏 + 高亮内容；>5000 行分块/虚拟滚动；Loading 骨架行；Error 见下
- **执行提示卡**（--color-warnBg 底 + --color-update 文字 + lucide AlertTriangle 16）：`本工具不自动执行回滚。请在 MySQL 客户端人工执行；执行前建议先开启事务并备份数据，确认无误后 COMMIT。`

### 关键交互
1. 下载：Blob('text/sql;charset=utf-8') → a.download=`rollback_库_表_20260822.sql`（AC-10）
2. 复制：navigator.clipboard.writeText，失败降级 textarea+execCommand
3. 事务顺序：多事务按提交逆序、事务内正序（AC-09）——注释块分隔，含事务号/提交时间便于人工核对

### 空态/错误文案
- 无脚本：`未生成回滚脚本：当前没有选中的变更。请返回变更列表页勾选需要回滚的记录。`
- 生成失败：`回滚脚本生成失败：变更数据缺失。请返回变更列表重新选择。`

---

## RoleVerdict

```
verdict: pass
blocking: []
advisory:
  - 明细弹窗遮罩 rgba(0,0,0,.45) 属 #000 例外；若团队要求纯 Token，可新增 --color-overlay（C-extension）由前端维护
  - 三态色 pill 采用"1px 边框+同色文字"避免新增浅色背景 Token；若视觉偏淡可向架构师申请 C-extension（如 --color-insertBg），本次未动锁定值
  - 深色主题 v1.5 预留：本方案全部经 CSS 变量引用，切深色仅需覆盖 :root 变量
  - 变更列表 10 万行级性能依赖虚拟滚动/分页，需与前端确认渲染策略（AC-11）
evidence:
  - page-design-prompts.md: 5 页设计提示词全量（含三态色方案/SQL 高亮/diff 视图）
  - design-tokens.css: 全部引用 Token 与文件一致（--color-background/--color-warnBg 采用 CSS 文件命名）
```
