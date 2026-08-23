# Phase 2 架构细化：WS 协议 v2 + Binlog 事件 Schema + 回滚算法 + WASM 边界 + TypePHP 编译配置

> 产出：首席架构师（高见远） | 基线：Spec v1.0 §5 | 状态：待 Phase 2 门禁
> 规则遵守：无 emoji 图标、无紫粉渐变、无硬编码颜色（例外 #fff/#000）、无占位文案。

---

## 1. WS 代理消息协议 v2

### 1.1 统一帧（所有消息包裹）

```json
{
  "v": 2,
  "id": "m-9f2c",              // 请求-响应关联，浏览器生成，UUID/自增均可
  "type": "binlog-event",      // 消息类型
  "ts": 1724300000123,         // 毫秒时间戳
  "payload": {}
}
```

### 1.2 消息定义（字段级）

| type | 方向 | payload 字段 | 类型 | 必填 | 说明 |
|------|------|--------------|------|------|------|
| connect | 浏览器→代理 | host / port / user / password / database / serverId / connectTimeoutMs | string / int / string / string / string? / int? / int | 必 / 必 / 必 / 必 / 选 / 选 / 选 | database 可空（追踪前再选库）；serverId 缺省由代理随机 [1, 2^31) |
| connected | 代理→浏览器 | ok / serverVersion / binlogFile / binlogPos / binlogFormat / binlogRowImage / hasBinlog | bool / string / string? / int? / string / string / bool | 必 | 前置检查字段随 connected 带回，供 check_binlog_cfg 使用 |
| binlog-dump | 浏览器→代理 | binlogFile / binlogPos / slaveFlags | string / int / int? | 必 / 必 / 选 | slaveFlags=0（非阻塞、无伪从机注册） |
| binlog-event | 代理→浏览器 | raw / eventType / binlogFile / binlogPos / timestamp / serverId | string(base64) / int / string / int / int / int | 必 | raw=完整事件字节(19B 头+body) Base64；eventType 代理解析头 19B 得出，便于浏览器预筛 |
| query | 浏览器→代理 | sql / database | string / string? | 必 / 选 | 仅用于 INFORMATION_SCHEMA 补列名（只读 SELECT，代理侧白名单校验前缀） |
| query-result | 代理→浏览器 | columns / rows / affectedRows | array[{name,type}] / array[array] / int? | 必 / 必 / 选 | columns 含列名；rows 值为字符串化（代理侧保证 BIGINT 不丢精度） |
| error | 双向 | code / message / detail | int / string / string? | 必 / 必 / 选 | detail 可含 MySQL 原始错误文本 |
| heartbeat | 双向 | ts / binlogPos | int / int? | 必 / 选 | 代理侧保活主用；binlogPos 供前端展示解析进度 |
| close | 浏览器→代理 | reason | string? | 选 | 主动释放连接（关闭 dump 线程） |

### 1.3 错误码表（≥8，实测 13 个）

| code | 名称 | 场景 | 前端动作 |
|------|------|------|----------|
| 1001 | AUTH_FAILED | 用户名/密码错误 | 连接页报错 |
| 1002 | NETWORK_UNREACHABLE | TCP 连不上 host:port / 代理未连上目标 | 连接页报错 |
| 1003 | BINLOG_DISABLED | log_bin=OFF 或无 binlog 文件 | 阻断追踪（AC-03） |
| 1004 | PERMISSION_DENIED | 缺 SELECT/REPLICATION SLAVE/REPLICATION CLIENT | 提示授权 SQL |
| 1005 | PARSE_ERROR | 事件解码失败（长度/类型不符） | 跳过该事件并计数 |
| 1006 | PROXY_NOT_READY | 代理进程未启动/未监听 | 提示启动代理 |
| 1007 | TIMEOUT | connect 或 query 超时 | 重试或提示 |
| 1008 | INVALID_PARAM | 帧字段缺失/类型错误 | 前端校验 |
| 1009 | SERVER_ID_CONFLICT | serverId 已被占用（代理内存会话表或 MySQL 复制冲突 ER_MASTER_FATAL_ERROR_READING_BINLOG=1236） | 换 serverId 重连 |
| 1010 | PROTOCOL_ERROR | 非预期帧/版本不符/query 非只读被拒 | 断开重连 |
| 1011 | BINLOG_POSITION_INVALID | binlog 文件不存在或 pos 越界（ER_MASTER_FATAL_ERROR_READING_BINLOG） | 提示重新选起止位 |
| 1012 | TRANSACTION_COMPRESSED | MySQL 8.0.20+ binlog_transaction_compression=ON 事件无法解码 | 提示关闭该参数 |
| 1013 | META_MISSING | INFORMATION_SCHEMA 补齐列名失败 | 提示手动补元数据 |

### 1.4 心跳保活

- 代理→浏览器：dump 期间若无 binlog-event 超过 15s，发 heartbeat { ts, binlogPos }。
- 浏览器侧：45s 无任何帧判定断线，UI 提示并自动重连（重连复用 connect → binlog-dump 续传）。
- 浏览器→代理 heartbeat 可选（探测代理存活），代理回心跳帧应答。

### 1.5 server-id 冲突处理

1. connect 时浏览器未填 serverId → 代理随机生成（1~2^31-1）并回传 connected.serverId。
2. 代理维护活跃会话 serverId 表：重复 → 拒绝 connect，error 1009。
3. MySQL 侧复制冲突（其它从机占用）：binlog-dump 线程被杀或返回 1236 → 代理捕获 → error 1009（detail 含 MySQL 原始错误）。

---

## 2. Binlog 事件 JSON Schema

### 2.1 编码环节划分（Base64 在哪做）

```
MySQL 3306 --原始TCP字节--> 代理 --Base64编码--> 浏览器 --原样传递--> parse_binlog(events_json)
                                                                        └ PHP base64_decode → 二进制字节串 → 解析
```

- **代理端**：读取复制协议事件完整字节（19B 头 + body），Base64 编码进 `binlog-event.payload.raw`。
- **WASM 边界**：全程 ASCII（Base64 + JSON），规避 WIT `string` 的 UTF-8 合法性校验；PHP 侧 `base64_decode()` 还原二进制字节串（PHP string 原生字节语义）。
- 浏览器 JS 不做任何二进制解码，只做透传与筛选。

### 2.2 parse_binlog 输入（events_json）

```json
{
  "events": [
    { "type": "table_map",  "rawBase64": "<base64>", "binlogFile": "mysql-bin.000003", "binlogPos": 1234,  "timestamp": 1724300000, "serverId": 1 },
    { "type": "write_rows", "rawBase64": "<base64>", "binlogFile": "mysql-bin.000003", "binlogPos": 5678,  "timestamp": 1724300000, "serverId": 1 },
    { "type": "update_rows","rawBase64": "<base64>", "binlogFile": "mysql-bin.000003", "binlogPos": 7890,  "timestamp": 1724300001, "serverId": 1 },
    { "type": "delete_rows","rawBase64": "<base64>", "binlogFile": "mysql-bin.000003", "binlogPos": 9001,  "timestamp": 1724300002, "serverId": 1 }
  ],
  "metadata": {
    "database": "test",
    "tables": {
      "test.tbl": {
        "columns": [
          { "name": "id",         "type": 3,   "signed": true,  "length": 11 },
          { "name": "name",       "type": 253, "charset": "utf8mb4", "length": 255 },
          { "name": "amount",     "type": 246, "precision": 10, "scale": 2 },
          { "name": "created_at", "type": 12,  "length": 19 }
        ]
      }
    }
  }
}
```

- **列类型/元数据来源 = TableMap 事件流**（表 id、schema/table 名、列类型字节、metadata、null bitmap 均在事件内），不依赖外部查询。
- **列名来源优先级**：① TableMap 内嵌列名（MySQL 8.0.1+ binlog_row_metadata=FULL）→ ② metadata.tables 的 INFORMATION_SCHEMA 快照（MySQL 5.7 默认 MINIMAL 无列名）。
- **MySQL 5.7 补充方案**：追踪工单提交时，浏览器先发 `query` 查 `INFORMATION_SCHEMA.COLUMNS` 拉取所选库表列名 → 随 events 一并传入；解析遇到 metadata 缺失的表 → error 1013。DDL 中途变更不追踪（Spec 锁定 Out-of-Scope），快照在 dump 期间视为稳定。
- 值统一字符串化（AC-12）：BIGINT/DECIMAL 字符串直出；NULL→null；BLOB/二进制→`"base64:<...>"`；JSON→文本串，解码失败则 `"base64:<...>"` + warning（5.7 二进制 JSON 格式 json_binary.cc）。

### 2.3 parse_binlog 输出（标准化变更）

```json
{
  "ok": true,
  "changes": [
    {
      "changeId": "c1",
      "schema": "test",
      "table": "tbl",
      "type": "insert",
      "columns": ["id", "name", "amount", "created_at"],
      "oldValues": null,
      "newValues": { "id": "1", "name": "张三", "amount": "199.95", "created_at": "2026-08-22 10:00:00" },
      "xid": 101,
      "timestamp": 1724300000,
      "binlogFile": "mysql-bin.000003",
      "binlogPos": 5678
    }
  ],
  "warnings": []
}
```

- 每条变更：schema/table/type(insert|update|delete)/columns[]/oldValues{}（delete 必填、insert 为 null）/newValues{}（insert/update 必填、delete 为 null）/xid/timestamp/binlogFile/binlogPos。
- xid 来源：XID_EVENT（binlog 事务提交事件）归属到其前最近的若干行事件；无 XID 时以 QUERY_EVENT(COMMIT) 兜底，仍无则以事件序合成 `xid=0-<pos>`。
- update 行：oldValues=before image，newValues=after image。
- TableMapCache 只缓存表 id→(schema/table/列类型/列名)，事件结束不跨 dump 保留（每次 dump 重建）。

---

## 3. 回滚 SQL 生成算法

### 3.1 flashback 三规则（对应 AC-06/07/08）

| 变更类型 | 回滚语句 | 条件构造 |
|----------|----------|----------|
| INSERT | `DELETE FROM \`t\` WHERE <全列=newValues>` | WHERE 全列等值 |
| UPDATE | `UPDATE \`t\` SET <oldValues> WHERE <newValues>` | SET=before，WHERE=after |
| DELETE | `INSERT INTO \`t\` (cols) VALUES (oldValues)` | 列清单 + 原值 |

### 3.2 WHERE 全列构造

- 按 columns 顺序 `c1=... AND c2=...` 全列等值；NULL 列用 `c IS NULL`。
- 唯一性保证依赖 binlog_row_image=FULL（AC-04 降级警告时明确提示精度风险）。

### 3.3 事务逆序排列（AC-09）

- 按 xid 分组 → 组按提交时刻（组内最大 timestamp / XID 事件序号）**降序**输出（后提交先回滚）。
- 组内保持原始事件顺序（正序）。
- 每个事务组包裹 `START TRANSACTION; ... COMMIT;`，保证原子回滚。

### 3.4 值转义规则（SqlLiteral）

| 值类型 | INSERT/SET 字面量 | WHERE 谓词 |
|--------|-------------------|------------|
| 数字 int/decimal/float | 原样（字符串直出，无引号） | 原样 |
| 字符串 | 单引号；`'`→`''`，`\`→`\\`（兼容 NO_BACKSLASH_ESCAPES） | 同上 |
| NULL | NULL | `IS NULL` |
| 日期/时间 DATETIME/DATE/TIME/TIMESTAMP/YEAR | `'YYYY-MM-DD HH:MM:SS'` | 同上 |
| BLOB/二进制 | `X'<hex>'` | `X'<hex>'` |
| JSON | `'<json 文本>'` | 同上 |
| BIT/BOOL | 1 / 0 | 同上 |

- 语句前注释：`-- changeId=c1 ; binlog=mysql-bin.000003:5678 ; xid=101`（审计追溯）。
- 输出：头部注释（生成时间、解析核心版本）+ 逐事务 `START TRANSACTION; ... COMMIT;`。

---

## 4. WASM 边界设计（三导出函数 + 完整示例数据流）

### 4.1 导出函数（WIT 名 kebab-case → JS 侧 Jco 生成 camelCase，如 get-extension-info → getExtensionInfo）

```php
#[WasmExport(name: 'parse-binlog')]
function parse_binlog(string $events_json): string { /* 输入见 §2.2，输出见 §2.3 */ }

#[WasmExport(name: 'generate-rollback')]
function generate_rollback(string $changes_json): string { /* 输入=changes 数组，输出={ok,sql,stats} */ }

#[WasmExport(name: 'check-binlog-cfg')]
function check_binlog_cfg(string $meta_json): string { /* 前置检查 */ }
```

### 4.2 示例：INSERT → UPDATE → DELETE 完整数据流

**① raw 事件（代理→浏览器，base64 截断示意）**

```json
{ "v":2, "id":"m3", "type":"binlog-event", "ts":1724300000000,
  "payload": { "raw":"<base64 事件字节>", "eventType":30,
               "binlogFile":"mysql-bin.000003", "binlogPos":5678,
               "timestamp":1724300000, "serverId":1 } }
```

（eventType：19=TableMap；30/31/32=Write/Update/DeleteRows V2；16=XID）

**② parse_binlog 输出（3 条变更）**

```json
{
  "ok": true,
  "changes": [
    { "changeId":"c1", "schema":"test", "table":"tbl", "type":"insert",
      "columns":["id","name","amount","created_at"], "oldValues":null,
      "newValues":{ "id":"1","name":"张三","amount":"199.95","created_at":"2026-08-22 10:00:00" },
      "xid":101, "timestamp":1724300000, "binlogFile":"mysql-bin.000003", "binlogPos":5678 },
    { "changeId":"c2", "schema":"test", "table":"tbl", "type":"update",
      "columns":["id","name","amount","created_at"],
      "oldValues":{ "id":"2","name":"李四","amount":"50.00","created_at":"2026-08-22 10:01:00" },
      "newValues":{ "id":"2","name":"李四改","amount":"60.00","created_at":"2026-08-22 10:01:30" },
      "xid":102, "timestamp":1724300001, "binlogFile":"mysql-bin.000003", "binlogPos":7890 },
    { "changeId":"c3", "schema":"test", "table":"tbl", "type":"delete",
      "columns":["id","name","amount","created_at"],
      "oldValues":{ "id":"3","name":"王五","amount":"88.00","created_at":"2026-08-22 10:02:00" },
      "newValues":null,
      "xid":103, "timestamp":1724300002, "binlogFile":"mysql-bin.000003", "binlogPos":9001 }
  ],
  "warnings": []
}
```

**③ generate_rollback 输出（xid 103→102→101 逆序，事务内正序）**

```json
{ "ok": true, "sql": "...见下方 SQL 文本...", "stats": { "statements": 3, "transactions": 3 } }
```

```sql
-- 回滚脚本 binlog-parser v1.0；生成时间 2026-08-22 10:05:00
START TRANSACTION;
-- changeId=c3 DELETE test.tbl @ mysql-bin.000003:9001 ; xid=103
INSERT INTO `test`.`tbl` (`id`,`name`,`amount`,`created_at`)
VALUES (3,'王五','88.00','2026-08-22 10:02:00');
COMMIT;

START TRANSACTION;
-- changeId=c2 UPDATE test.tbl @ mysql-bin.000003:7890 ; xid=102
UPDATE `test`.`tbl` SET `id`=2,`name`='李四',`amount`='50.00',`created_at`='2026-08-22 10:01:00'
WHERE `id`=2 AND `name`='李四改' AND `amount`='60.00' AND `created_at`='2026-08-22 10:01:30';
COMMIT;

START TRANSACTION;
-- changeId=c1 INSERT test.tbl @ mysql-bin.000003:5678 ; xid=101
DELETE FROM `test`.`tbl`
WHERE `id`=1 AND `name`='张三' AND `amount`='199.95' AND `created_at`='2026-08-22 10:00:00';
COMMIT;
```

**④ check_binlog_cfg 示例**

```json
// 输入
{ "serverVersion":"5.7.44", "hasBinlog":true, "binlogFormat":"ROW", "binlogRowImage":"FULL",
  "userPrivileges":["SELECT","REPLICATION SLAVE","REPLICATION CLIENT"] }
// 输出
{ "ok": true, "errors": [], "warnings": [] }
// 反例：format=MIXED → { ok:false, errors:[{code:1003,message:"binlog_format 必须为 ROW"}] }
//       rowImage=MINIMAL → { ok:true, warnings:[{code:1004,message:"WHERE 全列精度降级"}] }
```

---

## 5. TypePHP 编译配置与源码规划

### 5.1 parser/project.yml（与 examples/wasm-hello 的 library 模式语法一致）

```yaml
name: binlog-parser
mode: library
build-dir: build
output: component/binlog-parser.wasm
sources:
  - src
wasm: browser
wasm-browser-dir: generated
wasm-package: typephp:binlog-parser@1.0.0
wasm-world: binlog-parser
```

- 缺省 `target-platform: wasm32-wasip2`；构建：`php bin/tpc.php parser/project.yml`（需 WASI SDK + jco 在 PATH）。
- 代理端独立工程 `agent/project.yml`（`mode: bin`，产出单文件 WS TCP 代理）。

### 5.2 src/ 目录规划（单文件单一职责，入口只装配）

```
parser/src/
├── BinlogExport.php          # 3 个 #[WasmExport] 入口：解码 JSON → 调用 → 编码 JSON（不写业务）
├── Event/
│   ├── EventHeader.php       # 19B 事件头：timestamp/type/serverId/eventSize/logPos/flags
│   ├── EventDecoder.php      # 类型分发：TableMap/Write/Update/DeleteRows/XID/Rotate
│   └── TableMapCache.php     # table_id → (schema,table,列类型元数据,列名) 缓存
├── Codec/
│   ├── LengthCoded.php       # length-encoded int/string 读取
│   ├── TypeDecoder.php       # 列值解码：int/float/decimal/string/datetime/json/blob
│   └── JsonBinary.php        # MySQL 二进制 JSON 解码（json_binary.cc 格式）
├── Change/
│   └── ChangeBuilder.php     # 行事件 → 标准化变更（old/new/xid/pos 归属）
├── Rollback/
│   ├── RollbackGenerator.php # flashback 三规则 + WHERE 全列
│   ├── SqlLiteral.php        # 值转义（字符串/日期/NULL/BLOB/JSON/数字）
│   └── TransactionGrouper.php# xid 分组 + 事务提交逆序
└── Config/
    └── CheckBinlogCfg.php    # ROW/FULL/权限/binlog 开启前置校验
```

- 解析器只依赖 PHP 内置类型 + json 扩展；BIGINT/DECIMAL 全程字符串运算，不引入高精度库（保持 WASM 体积）。
- 编译目标 ≤8MB（Spec §10）。

---

## 6. 关键边界澄清（对 Spec §5 的细化，非推翻）

| Spec 表述 | 细化结论 |
|-----------|----------|
| "binlog 字节流(string)进" | 因 WIT string 为 UTF-8，原始二进制不得直跨边界 → 代理端 Base64，PHP 内 base64_decode（§2.1） |
| "parse_binlog(events_json)" | 输入 = events[]（每项含 rawBase64）+ metadata{}（INFORMATION_SCHEMA 列名快照）（§2.2） |
| 消息"双向 error" | 统一帧 + 13 错误码 + detail（§1.3） |
| check_binlog_cfg | 输入来自 connected 回传字段 + 权限探测；输出 errors/warnings 驱动 AC-03/AC-04（§4.2④） |
