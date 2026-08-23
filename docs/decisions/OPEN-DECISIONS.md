# OPEN DECISIONS 登记册

| Date | Source | Open Item | Related Constraints | Current Leaning | Blocked By | Resolves When | Status |
|------|--------|-----------|---------------------|-----------------|------------|---------------|--------|
| ~~2026-08-22~~ | Phase 1 | ~~解析核心选型~~ | — | — | — | 用户已决策 | **RESOLVED** |
| ~~2026-08-22~~ | Phase 1 | ~~桥接方案~~ | — | — | — | 用户已决策 | **RESOLVED** |
| ~~2026-08-22~~ | Phase 1 | ~~回滚 SQL 精度~~ | — | — | — | 用户已决策 | **RESOLVED** |

**已决项（RESOLVED）**：
| Date | Source | Item | Resolution |
|------|--------|------|------------|
| 2026-08-22 | Phase 1 | 纯浏览器零服务端直连 MySQL 是否可行 | 不可行（TCP 硬约束），必须最小桥接面 —— 已确认 |
| 2026-08-22 | Phase 1 | 是否存在浏览器内 binlog 追踪现成完整产品 | 不存在（市场空白），需自研组装 —— 已确认 |
| 2026-08-22 | Phase 1 | 许可红线 | AGPL（Yearning）只参考不抄；MIT（zongji）/Apache（rust crate）可复用 —— 已确认 |
| 2026-08-22 | Phase 1 | 解析核心选型 | **用户决策：TypePHP（aot-compiler）编译 PHP 解析核心为 WASM（browser 模式，Jco 集成），同时原生模式编独立二进制给代理端。不移植 zongji，PHP 自研解析（参考 zongji 事件格式/类型解码）** —— 已确认 |
| 2026-08-22 | Phase 1 | 桥接方案 | **用户决策：内网单文件 WS 代理（TypePHP 原生二进制，免 PHP 环境），CF Workers 列为后续可选** —— 已确认 |
| 2026-08-22 | Phase 1 | 回滚 WHERE 精度 | **用户决策：WHERE 用全列（binlog_row_image=FULL）** —— 已确认 |

**AOT 编译 ABI 约束（TypePHP wasm browser 模式）**：导出函数仅支持 bool/int/float/string/void（int→WIT s64→JS bigint），数组/对象/引用/生成器为编译错误 → 接口设计为"binlog 字节流(string)进，JSON 事件流(string)出"，复杂数据结构走 JSON 序列化边界。
