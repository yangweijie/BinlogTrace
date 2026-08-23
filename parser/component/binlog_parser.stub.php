<?php

/** @import-library */

namespace {
    function parse_binlog(string $events_json): string
    {
    }
    function generate_rollback(string $changes_json): string
    {
    }
    function check_binlog_cfg(string $meta_json): string
    {
    }
}
namespace Typephp\BinlogParser\Change {
    /** 将解码后的行数据组装为标准化 Change 数组（供前端变更列表消费） */
    final class ChangeBuilder
    {
        /**
         * @param  array   $decodedRows  EventDecoder 输出的行数据
         *   write/delete: array<columnIndexStr, valueStr|null>
         *   update:       array{before: array<...>, after: array<...>}
         * @return array{changes: array[], counter: int}
         */
        public static function build(\Typephp\BinlogParser\Event\TableMapCache $cache, string $kind, array $decodedRows, int $tableId, int $xid, int $timestamp, string $binlogFile, int $binlogPos, int $counter): array
        {
        }
        private static function mapKind(string $kind): string
        {
        }
        /** 将按列索引索引的行数据映射为按列名索引的值字典 */
        private static function rowToValues(array $row, array $columns): array
        {
        }
    }
}
namespace Typephp\BinlogParser\Codec {
    /** 解析 binlog 日期时间变体：DATETIME2 / TIMESTAMP2 / TIME2 + MySQL NEWDATE。
     *
     * 所有输出统一为字符串，保证精度（AC-12）。
     */
    final class DateTimeBinary
    {
        /** MySQL NEWDATE（天计数 → 日期字符串） */
        public static function mysqlDaysToDate(int $days): string
        {
        }
        /** DATETIME2：5/6/7/8 字节。返回 [value, consumed]。 */
        public static function decodeDatetime2(string $bytes, int $offset, int $frac): array
        {
        }
        /** TIMESTAMP2：4/5/6/7 字节。返回 [value, consumed]。 */
        public static function decodeTimestamp2(string $bytes, int $offset, int $frac): array
        {
        }
        /** TIME2：3/4/5 字节。返回 [value, consumed]。 */
        public static function decodeTime2(string $bytes, int $offset, int $frac): array
        {
        }
        private static function datetime2Size(int $frac): int
        {
        }
        private static function fracBytes(int $frac): int
        {
        }
        /** 读取 frac 字段（1-3 字节 LE）→ 整数 */
        public static function readFrac(string $s, int $offset, int $frac): int
        {
        }
    }
    /** 解析 MySQL NEWDECIMAL 二进制格式（binlog 列值）→ 十进制字符串。
     *
     * 格式：高位字节最高 bit 为符号位；每 4 字节压缩 9600 范围（2 位/字节），
     * 不足 4 字节时字节数 = ceil(digits*0.5)+1 并左对齐。
     * 参考：https://dev.mysql.com/doc/internals/newdecimal-byte-order.html
     */
    final class DecimalBinary
    {
        /** 解码 NEWDECIMAL 字节 → 字符串 */
        public static function decode(string $bytes, int $precision, int $scale): string
        {
        }
        /** 计算不足 4 字节（未压缩）的字节数 */
        private static function uncompressedBytes(int $digits): int
        {
        }
        /** 解码一个 decimal 部分（整数或小数）的字节序列 */
        private static function decodeDecimalPart(string $bytes, int $start, int $byteLen, int $digitLen): string
        {
        }
        private static function isZero(string $s): bool
        {
        }
    }
    /** 读取 MySQL length-coded 编码的整数与字节串（LE 字节序） */
    final class LengthCoded
    {
        public static function readInt(string $buf, int $offset = 0): array
        {
        }
        public static function readBytes(string $buf, int $offset = 0): array
        {
        }
        public static function readUint16LE(string $buf, int $offset = 0): array
        {
        }
        public static function readUint32LE(string $buf, int $offset = 0): array
        {
        }
        public static function readUint64LE(string $buf, int $offset = 0): array
        {
        }
        public static function readBytesN(string $buf, int $offset, int $n): array
        {
        }
        private static function u16(string $buf, int $o): int
        {
        }
        private static function u24(string $buf, int $o): int
        {
        }
        private static function u32(string $buf, int $o): int
        {
        }
    }
    /** 按 MySQL 列类型解码单个非空值（AC-12：值统一字符串化）。
     *
     * 返回 ['value' => string|null, 'consumed' => int]。
     * meta 由 TABLE_MAP 事件提供（bitCount/precision/scale/fracDigits/values/length）。
     */
    final class TypeDecoder
    {
        public static function decode(string $bytes, int $offset, int $mysqlType, array $meta = []): array
        {
        }
        private static function decodeVarString(string $bytes, int $offset, array $meta): array
        {
        }
    }
}
namespace Typephp\BinlogParser\Config {
    /** binlog 前置配置校验（AC-13；Spec v1.1 §13.1）
     *
     * 检测项：
     *   - hasBinlog=false            → error 1003（binlog 未开启）
     *   - binlogFormat != ROW        → error 1003
     *   - binlogRowImage = MINIMAL   → warning 1004（WHERE 全列精度降级）
     *   - binlogRowImage = NO        → error 1004（不记录行镜像）
     *   - 缺 SELECT                  → error 1004
     *   - 缺 REPLICATION SLAVE       → error 1004
     *   - 缺 REPLICATION CLIENT      → warning 1004
     *
     * 每项 error/warning 附带 fix 引导（kind: mycnf/grant/dynamic/tip + lines）。
     */
    final class CheckBinlogCfg
    {
        public static function check(array $meta): array
        {
        }
        private static function hasPriv(array $privileges, string $name): bool
        {
        }
    }
}
namespace Typephp\BinlogParser\Event {
    /** binlog 单事件解码（base64 → 头部 → 类型分发 → 行数据解码）。
     *
     * 输出结构：
     *   xid         → {kind:'xid', xid:int}
     *   table_map   → {kind:'table_map', tableMap:{...}}
     *   write_rows  → {kind:'write_rows', rows:[{colIdx=>value}], tableId:int}
     *   update_rows → {kind:'update_rows', rows:[{before:{...},after:{...}}], tableId:int}
     *   delete_rows → {kind:'delete_rows', rows:[{colIdx=>value}], tableId:int}
     *   skip        → {kind:'skip'}
     */
    final class EventDecoder
    {
        private const XID = 16;
        private const TABLE_MAP = 19;
        private const HEARTBEAT = 24;
        private const WRITE_ROWS_V2 = 30;
        private const UPDATE_ROWS_V2 = 31;
        private const DELETE_ROWS_V2 = 32;
        private const STOP = 33;
        /**
         * @return array{kind:string, xid:int|null, tableMap:array|null, rows:array, tableId:int, warnings:string[]}
         */
        public static function decodeSingle(string $rawBase64, \Typephp\BinlogParser\Event\TableMapCache $cache): array
        {
        }
        private static function decodeXid(string $body): array
        {
        }
        private static function decodeTableMap(string $body, array &$warnings): array
        {
        }
        private static function decodeRows(string $body, string $kind, \Typephp\BinlogParser\Event\TableMapCache $cache, bool $isUpdate, array &$warnings): array
        {
        }
        private static function decodeExtraData(string $body, int $start, int $end, int $bitmapBytes, int $columnCount, array $types, array $metaData, array &$warnings): array
        {
        }
        private static function decodeRowValues(string $body, int $offset, int $end, string $nullBitmap, string $colBitmap, int $columnCount, array $types, array $metaData, array &$warnings): ?array
        {
        }
        private static function bitSet(string $bitmap, int $idx): bool
        {
        }
    }
    /** 解析 binlog 事件 19 字节头部（LE 字节序） */
    final class EventHeader
    {
        public static function parse(string $raw): array
        {
        }
    }
    /** TABLE_MAP 事件解析 + tableId → 表元数据 缓存（每次 dump 重建，不跨 dump 保留） */
    final class TableMapCache
    {
        /** @var array<int, array>  tableId => {tableId,schema,table,columns,types,metaData,columnCount} */
        private array $map = [];
        public function put(int $tableId, array $info): void
        {
        }
        public function get(int $tableId): ?array
        {
        }
        /** 用前端传入的 INFORMATION_SCHEMA 元数据补充列名（MySQL 5.7 MINIMAL 兜底） */
        public function applyMetadata(array $metadata): void
        {
        }
        /** 解析 TABLE_MAP 事件体，返回表元数据数组（不写入缓存，由调用方 put） */
        public static function parseTableMap(string $body): array
        {
        }
        private static function parseColumnMetadata(string $body, int $start, int $end, array $types): array
        {
        }
    }
}
namespace Typephp\BinlogParser\Rollback {
    /** flashback 回滚 SQL 生成（AC-06/07/08/09/10）
     *
     * 三规则：
     *   INSERT → DELETE FROM t WHERE <全列=newValues>
     *   UPDATE → UPDATE t SET <oldValues> WHERE <newValues>
     *   DELETE → INSERT INTO t (cols) VALUES (oldValues)
     *
     * 事务按 xid 提交时刻降序，组内正序，事务包裹 START TRANSACTION / COMMIT。
     */
    final class RollbackGenerator
    {
        public static function generate(array $changes): array
        {
        }
        private static function buildStatement(string $type, string $t, array $columns, ?array $oldValues, ?array $newValues): string
        {
        }
        private static function formatValues(array $columns, ?array $values): string
        {
        }
    }
    /** SQL 字面量转义与子句构造（对应 Spec 值转义规则与前端 rollback-gen.ts 算法） */
    final class SqlLiteral
    {
        /** 判断是否为纯数字字面量（int 或 decimal，无引号直出） */
        public static function isNumericLiteral(string $v): bool
        {
        }
        /** 判断是否为 X'..' 十六进制字面量 */
        public static function isHexLiteral(string $v): bool
        {
        }
        /** 单值转义：数字/16 进制原样；字符串单引号并转义；空值调用方处理为 NULL */
        public static function literal(string $v): string
        {
        }
        /** WHERE c1=... AND c2=...（全列等值，NULL 列用 IS NULL） */
        public static function whereClause(array $values, array $columns): string
        {
        }
        /** SET c1=..., c2=... */
        public static function setClause(array $values, array $columns): string
        {
        }
        /** 反引号包裹的列名清单 */
        public static function colList(array $columns): string
        {
        }
        public static function quoteIdentifier(string $s): string
        {
        }
    }
    /** 按 xid 分组并按提交时刻降序排列事务（AC-09：后提交先回滚，组内正序） */
    final class TransactionGrouper
    {
        /**
         * @param  array[]  $changes  parse_binlog 输出的 change 数组
         * @return array[]  排序后的事务分组，每组为 change 数组
         */
        public static function group(array $changes): array
        {
        }
    }
}
