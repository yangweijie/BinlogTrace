# Phase 3 开发门禁检查清单（总监使用）

## 1. 代码组织门禁（对照 code-organization 标准）
- [ ] 目录分层：入口只装配零业务；业务逻辑下沉 service/模块
- [ ] 单文件 ≤300 行（前端组件/后端类）
- [ ] 依赖只向下（不循环引用）
- [ ] 前端：Vite 工程可 `npm run build`；Web Worker 加载 WASM 不阻塞主线程
- [ ] 后端：parser/ 与 agent/ 两个独立子工程；project.yml 语法与 wasm-hello 对齐

## 2. P0 规则扫描（所有 .tsx/.ts/.vue/.jsx/.php/.yml 文件）
- [ ] Emoji 正则扫描（零容忍）
  `[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{FE00}-\x{FE0F}\x{1F000}-\x{1F02F}\x{1F0A0}-\x{1F0FF}\x{1F100}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{200D}\x{20E3}]`
- [ ] 紫粉渐变（#7C3AED→#A855F7→#EC4899、Indigo→Pink）
- [ ] 硬编码颜色（唯一例外 #fff #000）——前端必须引用 design-tokens.css 变量
- [ ] 空洞占位文案

## 3. 前端验收（对应 AC-01~AC-13）
- [ ] 5 页面齐全（连接/追踪工单/变更列表/明细弹窗/回滚脚本）
- [ ] 连接页：WS 代理状态、测试连接、已存连接 localStorage
- [ ] 连接页：权限/格式检测引导 UI（AC-13）——check_binlog_cfg 结果逐项展示 + fix 展开 + 复制
- [ ] 追踪工单页：库/表级联、时间 ≤48h 校验、三态类型、阻断/降级（AC-03/04）
- [ ] 变更列表：三态色条、筛选、分页、生成回滚按钮（AC-05/11）
- [ ] 明细弹窗：diff 视图、BIGINT 字符串保真（AC-12）
- [ ] 回滚脚本页：SQL 高亮、复制/下载、事务注释（AC-06/07/08/09/10）
- [ ] WASM 集成：Web Worker + Jco createRuntime() 模式
- [ ] WS 客户端：协议 v2（9 消息 + 13 错误码 + 心跳 45s 判活）

## 4. 后端验收
- [ ] parser/：11 文件 4 层划分（Export/Event/Codec/Change/Rollback/Config）
- [ ] parser/：3 个 #[WasmExport]（parse-binlog/generate-rollback/check-binlog-cfg）
- [ ] check_binlog_cfg：权限检测 + fix 引导（GRANT/my.cnf/SET GLOBAL）符合 Spec v1.1 §13.1
- [ ] parser/：ABI 约束（全 string 进出、base64_decode 还原字节、无 Fiber/Generator）
- [ ] parser/：BIGINT/DECIMAL 字符串保真（AC-12）
- [ ] agent/：WS TCP 代理（connect/binlog-dump/事件 base64/心跳/server-id 冲突）
- [ ] agent/：前置探测（SHOW MASTER STATUS/@@binlog_format/@@binlog_row_image/SHOW GRANTS）
- [ ] 双工程 project.yml 语法正确

## 5. 一致性检查
- [ ] 前端调用的 WASM 函数名与后端实现一致（camelCase 由 Jco 生成）
- [ ] WS 消息字段与协议 v2 一致
- [ ] 错误码（1001~1013）前后端一致
- [ ] fix 引导内容与 Spec §13.1 一致
