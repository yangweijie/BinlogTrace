# Phase 2 设计门禁检查清单（总监使用）

## 1. P0 绝对规则扫描（每个产出必查）
- [ ] Emoji 图标正则扫描（零容忍，发现即退回）
  `[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{FE00}-\x{FE0F}\x{1F000}-\x{1F02F}\x{1F0A0}-\x{1F0FF}\x{1F100}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{200D}\x{20E3}]`
- [ ] 紫色→粉色渐变（#7C3AED→#A855F7→#EC4899、Indigo→Pink 组合）
- [ ] 空洞占位文案（Welcome to / Lorem ipsum / 欢迎使用 / 暂无数据）
- [ ] 硬编码颜色（唯一例外 #fff #000）——设计稿必须引用 design-tokens.css 变量
- [ ] 图标方案：必须锁定 lucide-react 一套，尺寸 16/20/24px

## 2. 架构师产出检查（协议/Schema 细化）
- [ ] WS 代理协议 v2：每种消息字段级完整定义
- [ ] 错误码表 ≥ 8 个
- [ ] 心跳保活机制明确
- [ ] server-id 冲突处理明确
- [ ] Binlog 事件 JSON Schema：变更记录字段完整（schema/table/type/columns/oldValues/newValues/xid/timestamp/binlogFile/binlogPos）
- [ ] MySQL 5.7 无 FULL 元数据时 INFORMATION_SCHEMA 补充方案
- [ ] 回滚算法：flashback 三规则 + WHERE 全列 + 事务逆序 + 值转义规则
- [ ] WASM 边界：3 导出函数输入输出 JSON 示例完整（INSERT/UPDATE/DELETE 各一）
- [ ] project.yml 配置与 wasm README 的 library 模式语法一致（mode: library, wasm: browser, wasm-package, wasm-world）
- [ ] 未超出 Spec 范围

## 3. 设计师产出检查（5 页设计提示词）
- [ ] 5 页齐全（连接/追踪工单/变更列表/明细弹窗/回滚脚本）
- [ ] 每页含：布局线框图、组件状态、交互说明、Token 引用、空态/错误态文案
- [ ] 变更列表三态色方案（--color-insert/update/delete）
- [ ] SQL 高亮配色未新增锁定外的颜色
- [ ] 明细 diff 视图设计
- [ ] 文案具体有引导性（如"未检测到 Binlog，请确认 MySQL 已开启 log_bin"）
- [ ] 未超出 Spec 范围

## 4. 一致性检查（跨产出）
- [ ] 设计师引用的 Token 与 design-tokens.json 一致
- [ ] 页面清单与 Spec §7 一致
- [ ] 架构师协议与 Spec §5 初稿兼容（是细化不是推翻）
