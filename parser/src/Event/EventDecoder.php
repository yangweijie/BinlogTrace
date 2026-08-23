<?php

declare(strict_types=1);

namespace Typephp\BinlogParser\Event;

use Typephp\BinlogParser\Codec\LengthCoded;
use Typephp\BinlogParser\Codec\TypeDecoder;

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
    public static function decodeSingle(string $rawBase64, TableMapCache $cache): array
    {
        $warnings = [];

        $raw = base64_decode($rawBase64, true);
        if ($raw === false) {
            return ['kind' => 'skip', 'xid' => null, 'tableMap' => null, 'rows' => [], 'tableId' => 0, 'warnings' => ['base64 解码失败']];
        }

        $len = strlen($raw);
        if ($len < 19) {
            return ['kind' => 'skip', 'xid' => null, 'tableMap' => null, 'rows' => [], 'tableId' => 0, 'warnings' => ['事件字节不足 19 字节']];
        }

        $header = EventHeader::parse($raw);
        $type = $header['eventType'];
        $body = substr($raw, 19);

        switch ($type) {
            case self::XID:
                return self::decodeXid($body);
            case self::TABLE_MAP:
                return self::decodeTableMap($body, $warnings);
            case self::WRITE_ROWS_V2:
                return self::decodeRows($body, 'write_rows', $cache, false, $warnings);
            case self::UPDATE_ROWS_V2:
                return self::decodeRows($body, 'update_rows', $cache, true, $warnings);
            case self::DELETE_ROWS_V2:
                return self::decodeRows($body, 'delete_rows', $cache, false, $warnings);
            case self::HEARTBEAT:
            case self::STOP:
            default:
                return ['kind' => 'skip', 'xid' => null, 'tableMap' => null, 'rows' => [], 'tableId' => 0, 'warnings' => []];
        }
    }

    private static function decodeXid(string $body): array
    {
        // XID_EVENT body 仅 8 字节 xid（uint64 LE），无 formatFlags 前缀
        if (strlen($body) < 8) {
            return ['kind' => 'skip', 'xid' => null, 'tableMap' => null, 'rows' => [], 'tableId' => 0, 'warnings' => ['XID 事件体不足']];
        }
        $xid = LengthCoded::readUint64LE($body, 0);
        return ['kind' => 'xid', 'xid' => (int)$xid['value'], 'tableMap' => null, 'rows' => [], 'tableId' => 0, 'warnings' => []];
    }

    private static function decodeTableMap(string $body, array &$warnings): array
    {
        try {
            $info = TableMapCache::parseTableMap($body);
            return ['kind' => 'table_map', 'xid' => null, 'tableMap' => $info, 'rows' => [], 'tableId' => (int)($info['tableId'] ?? 0), 'warnings' => $warnings];
        } catch (\Throwable $e) {
            $warnings[] = 'TableMap 解析失败：' . $e->getMessage();
            return ['kind' => 'skip', 'xid' => null, 'tableMap' => null, 'rows' => [], 'tableId' => 0, 'warnings' => $warnings];
        }
    }

    private static function decodeRows(string $body, string $kind, TableMapCache $cache, bool $isUpdate, array &$warnings): array
    {
        $len = strlen($body);
        if ($len < 12) {
            $warnings[] = '行事件体不足';
            return ['kind' => $kind, 'xid' => null, 'tableMap' => null, 'rows' => [], 'tableId' => 0, 'warnings' => $warnings];
        }

        // V2 行事件：table_id(6) + flags(2) + extra_data_len(2，含自身 2 字节) + column_count(lenenc)
        // + columns_bitmap（update 事件为 before/after 两个影像位图）+ 行数据
        $lo = LengthCoded::readUint32LE($body, 0);
        $hi = LengthCoded::readUint16LE($body, 4);
        $tableId = $lo['value'] + ($hi['value'] * 4294967296);
        $offset = 6 + 2; // 跳过 flags

        $extra = LengthCoded::readUint16LE($body, $offset);
        $extraContent = max(0, (int)$extra['value'] - 2);
        $offset += 2 + $extraContent;
        if ($offset >= $len) {
            $warnings[] = 'extra_data 越界';
            return ['kind' => $kind, 'xid' => null, 'tableMap' => null, 'rows' => [], 'tableId' => (int)$tableId, 'warnings' => $warnings];
        }

        $cc = LengthCoded::readInt($body, $offset);
        if ($cc['consumed'] === 0) {
            $warnings[] = 'column_count 读取失败';
            return ['kind' => $kind, 'xid' => null, 'tableMap' => null, 'rows' => [], 'tableId' => (int)$tableId, 'warnings' => $warnings];
        }
        $offset += $cc['consumed'];
        $columnCount = (int)$cc['value'];
        if ($columnCount === 0) {
            return ['kind' => $kind, 'xid' => null, 'tableMap' => null, 'rows' => [], 'tableId' => (int)$tableId, 'warnings' => $warnings];
        }

        $bitmapBytes = (int)ceil(($columnCount + 7) / 8);
        if ($offset + $bitmapBytes > $len) {
            $warnings[] = 'columns_bitmap 越界';
            return ['kind' => $kind, 'xid' => null, 'tableMap' => null, 'rows' => [], 'tableId' => (int)$tableId, 'warnings' => $warnings];
        }
        $beforeBitmap = substr($body, $offset, $bitmapBytes);
        $offset += $bitmapBytes;
        if ($isUpdate) {
            // update 事件携带 before/after 两个影像位图
            if ($offset + $bitmapBytes > $len) {
                $warnings[] = 'after 影像位图越界';
                return ['kind' => $kind, 'xid' => null, 'tableMap' => null, 'rows' => [], 'tableId' => (int)$tableId, 'warnings' => $warnings];
            }
            $afterBitmap = substr($body, $offset, $bitmapBytes);
            $offset += $bitmapBytes;
        } else {
            $afterBitmap = $beforeBitmap;
        }

        $info = $cache->get((int)$tableId);
        if ($info === null) {
            $warnings[] = '未找到 tableId=' . $tableId . ' 的 TableMap 元数据';
            return ['kind' => $kind, 'xid' => null, 'tableMap' => null, 'rows' => [], 'tableId' => (int)$tableId, 'warnings' => $warnings];
        }
        $types = (array)($info['types'] ?? []);
        $metaData = (array)($info['metaData'] ?? []);

        $rows = [];
        if ($isUpdate) {
            while ($offset + $bitmapBytes <= $len) {
                $start = $offset;
                $before = self::decodeOneRow($body, $offset, $beforeBitmap, $bitmapBytes, $columnCount, $types, $metaData, $warnings);
                if ($before === null || $before['_offset'] <= $start) {
                    break;
                }
                $offset = (int)$before['_offset'];
                $after = self::decodeOneRow($body, $offset, $afterBitmap, $bitmapBytes, $columnCount, $types, $metaData, $warnings);
                if ($after === null || $after['_offset'] <= $offset) {
                    break;
                }
                $offset = (int)$after['_offset'];
                unset($before['_offset'], $after['_offset']);
                $rows[] = ['before' => $before, 'after' => $after];
            }
        } else {
            while ($offset + $bitmapBytes <= $len) {
                $start = $offset;
                $row = self::decodeOneRow($body, $offset, $beforeBitmap, $bitmapBytes, $columnCount, $types, $metaData, $warnings);
                if ($row === null || $row['_offset'] <= $start) {
                    break;
                }
                $offset = (int)$row['_offset'];
                unset($row['_offset']);
                $rows[] = $row;
            }
        }

        return ['kind' => $kind, 'xid' => null, 'tableMap' => null, 'rows' => $rows, 'tableId' => (int)$tableId, 'warnings' => $warnings];
    }

    /** 解码一行：nullBitmap + 各列值（列存在性由 columns_bitmap 决定） */
    private static function decodeOneRow(string $body, int $offset, string $colBitmap, int $bitmapBytes, int $columnCount, array $types, array $metaData, array &$warnings): ?array
    {
        $len = strlen($body);
        if ($offset + $bitmapBytes > $len) {
            return null;
        }
        $nullBitmap = substr($body, $offset, $bitmapBytes);
        $offset += $bitmapBytes;
        return self::decodeRowValues($body, $offset, $len, $nullBitmap, $colBitmap, $columnCount, $types, $metaData, $warnings);
    }

    private static function decodeRowValues(string $body, int $offset, int $end, string $nullBitmap, string $colBitmap, int $columnCount, array $types, array $metaData, array &$warnings): ?array
    {
        $row = [];
        for ($i = 0; $i < $columnCount; $i++) {
            if (!self::bitSet($colBitmap, $i)) {
                continue;
            }
            if (self::bitSet($nullBitmap, $i)) {
                $row[(string)$i] = null;
                continue;
            }

            $mysqlType = $types[$i] ?? 0;
            $meta = $metaData[$i] ?? [];
            $decoded = TypeDecoder::decode($body, $offset, $mysqlType, $meta);

            if ($decoded['consumed'] === 0) {
                $warnings[] = '列 ' . $i . ' 值解码失败（mysqlType=' . $mysqlType . '）';
                $row[(string)$i] = null;
                continue;
            }

            $offset += $decoded['consumed'];
            $row[(string)$i] = $decoded['value'];
        }

        $row['_offset'] = $offset;
        return $row;
    }

    private static function bitSet(string $bitmap, int $idx): bool
    {
        $byteIdx = (int)($idx / 8);
        $bitIdx = $idx % 8;
        if ($byteIdx >= strlen($bitmap)) { return false; }
        return (ord($bitmap[$byteIdx]) & (1 << $bitIdx)) !== 0;
    }
}
