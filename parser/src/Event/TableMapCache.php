<?php

declare(strict_types=1);

namespace Typephp\BinlogParser\Event;

use Typephp\BinlogParser\Codec\LengthCoded;

/** TABLE_MAP 事件解析 + tableId → 表元数据 缓存（每次 dump 重建，不跨 dump 保留） */
final class TableMapCache
{
    /** @var array<int, array>  tableId => {tableId,schema,table,columns,types,metaData,columnCount} */
    private array $map = [];

    public function put(int $tableId, array $info): void
    {
        $this->map[$tableId] = $info;
    }

    public function get(int $tableId): ?array
    {
        return $this->map[$tableId] ?? null;
    }

    /** 用前端传入的 INFORMATION_SCHEMA 元数据补充列名（MySQL 5.7 MINIMAL 兜底） */
    public function applyMetadata(array $metadata): void
    {
        $tables = (array)($metadata['tables'] ?? []);
        foreach ($tables as $key => $info) {
            $columns = (array)($info['columns'] ?? []);
            $names = [];
            foreach ($columns as $col) {
                $names[] = (string)($col['name'] ?? '');
            }
            if (count($names) === 0) {
                continue;
            }
            foreach ($this->map as $tid => &$tableInfo) {
                $schemaTable = (($tableInfo['schema'] ?? '') . '.' . ($tableInfo['table'] ?? ''));
                $expected = (array)($tableInfo['columns'] ?? []);
                if ($schemaTable === $key && count($names) === count($expected)) {
                    $tableInfo['columns'] = $names;
                }
            }
        }
    }

    /** 解析 TABLE_MAP 事件体，返回表元数据数组（不写入缓存，由调用方 put） */
    public static function parseTableMap(string $body): array
    {
        $len = strlen($body);
        if ($len < 8) {
            throw new \ValueError('TABLE_MAP 事件体不足');
        }

        $lo = LengthCoded::readUint32LE($body, 0);
        $hi = LengthCoded::readUint16LE($body, 4); // table_id 仅 6 字节（5-6 字节为高 16 位）
        $tableId = $lo['value'] + ($hi['value'] * 4294967296);

        $offset = 6 + 2; // +2 flags

        // dbLen + dbName + NUL
        if ($offset + 1 > $len) { throw new \ValueError('TABLE_MAP db 字段缺失'); }
        $dbLen = ord($body[$offset]);
        $offset++;
        if ($offset + $dbLen + 1 > $len) { throw new \ValueError('TABLE_MAP db 越界'); }
        $db = substr($body, $offset, $dbLen);
        $offset += $dbLen + 1;

        // tableLen + tableName + NUL
        if ($offset + 1 > $len) { throw new \ValueError('TABLE_MAP table 字段缺失'); }
        $tableLen = ord($body[$offset]);
        $offset++;
        if ($offset + $tableLen + 1 > $len) { throw new \ValueError('TABLE_MAP table 越界'); }
        $table = substr($body, $offset, $tableLen);
        $offset += $tableLen + 1;

        // columnCount (length-encoded)
        $cc = LengthCoded::readInt($body, $offset);
        if ($cc['consumed'] === 0) { throw new \ValueError('TABLE_MAP columnCount 读取失败'); }
        $offset += $cc['consumed'];
        $columnCount = (int)$cc['value'];

        // columnTypes
        if ($offset + $columnCount > $len) { throw new \ValueError('TABLE_MAP columnTypes 越界'); }
        $types = [];
        for ($i = 0; $i < $columnCount; $i++) {
            $types[] = ord($body[$offset + $i]);
        }
        $offset += $columnCount;

        // metadataLengthCoded
        $ml = LengthCoded::readInt($body, $offset);
        if ($ml['consumed'] === 0) { throw new \ValueError('TABLE_MAP metadataLength 读取失败'); }
        $metaStart = $offset + $ml['consumed'];
        $metaLen = (int)$ml['value'];
        $metaEnd = $metaStart + $metaLen;
        if ($metaEnd > $len) { throw new \ValueError('TABLE_MAP metadata 越界'); }

        $colMeta = self::parseColumnMetadata($body, $metaStart, $metaEnd, $types);

        $columns = [];
        for ($i = 0; $i < $columnCount; $i++) {
            $columns[] = 'col' . $i;
        }

        return [
            'tableId' => $tableId,
            'schema' => $db,
            'table' => $table,
            'columns' => $columns,
            'types' => $types,
            'metaData' => $colMeta,
            'columnCount' => $columnCount,
        ];
    }

    private static function parseColumnMetadata(string $body, int $start, int $end, array $types): array
    {
        $result = [];
        $offset = $start;
        $n = count($types);

        for ($i = 0; $i < $n && $offset < $end; $i++) {
            $t = $types[$i];
            $meta = [];

            if ($t === 16 && $offset + 2 <= $end) { // BIT
                $meta['bitCount'] = ord($body[$offset]) | (ord($body[$offset + 1]) << 8);
                $offset += 2;
            } elseif ($t === 246 && $offset + 2 <= $end) { // NEWDECIMAL
                $meta['precision'] = ord($body[$offset]);
                $meta['scale'] = ord($body[$offset + 1]);
                $offset += 2;
            } elseif (in_array($t, [7, 13, 14], true) && $offset + 1 <= $end) { // DATETIME2/TIMESTAMP2/TIME2
                $meta['fracDigits'] = ord($body[$offset]);
                $offset++;
            } elseif (($t === 253 || $t === 254) && $offset + 2 <= $end) { // STRING/VARCHAR
                $meta['length'] = ord($body[$offset]) | (ord($body[$offset + 1]) << 8);
                $offset += 2;
            }
            // ENUM(247)/SET(248)/GEOMETRY(255)/JSON(15): 0 字节 metadata

            $result[$i] = $meta;
        }

        return $result;
    }
}
