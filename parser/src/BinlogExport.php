<?php

declare(strict_types=1);

/** WASM 导出入口：3 个 #[WasmExport] 函数（string 进，string 出）。
 *
 * 前端通过 Jco createRuntime() 调用：
 *   parseBinlog(eventsJson)    → parse_binlog
 *   generateRollback(changes)  → generate_rollback
 *   checkBinlogCfg(metaJson)   → check_binlog_cfg
 *
 * 本文件只装配，业务逻辑下沉 Event/Codec/Change/Rollback/Config 子目录。
 */

use Typephp\BinlogParser\Event\EventDecoder;
use Typephp\BinlogParser\Event\TableMapCache;
use Typephp\BinlogParser\Change\ChangeBuilder;
use Typephp\BinlogParser\Rollback\RollbackGenerator;
use Typephp\BinlogParser\Config\CheckBinlogCfg;

const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

#[WasmExport(name: 'parse-binlog')]
function parse_binlog(string $events_json): string
{
    try {
        $input = json_decode($events_json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($input)) {
            $input = [];
        }
        $events = (array)($input['events'] ?? []);
        $cache = new TableMapCache();
        $metadata = (array)($input['metadata'] ?? []);
        if (count($metadata) > 0) {
            $cache->applyMetadata($metadata);
        }
        $currentXid = 0;
        $changes = [];
        $warnings = [];
        $counter = 0;

        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $rawBase64 = (string)($ev['rawBase64'] ?? '');
            $binlogFile = (string)($ev['binlogFile'] ?? '');
            $binlogPos = (int)($ev['binlogPos'] ?? 0);
            $timestamp = (int)($ev['timestamp'] ?? 0);

            $result = EventDecoder::decodeSingle($rawBase64, $cache);
            if (!is_array($result)) {
                continue;
            }
            $kind = (string)($result['kind'] ?? 'skip');
            $w = (array)($result['warnings'] ?? []);
            if (count($w) > 0) {
                $warnings = array_merge($warnings, $w);
            }

            if ($kind === 'xid') {
                $currentXid = (int)($result['xid'] ?? $currentXid);
            } elseif ($kind === 'table_map' && ($result['tableMap'] ?? null) !== null) {
                $tm = $result['tableMap'];
                $tid = (int)($tm['tableId'] ?? 0);
                if ($tid > 0) {
                    $cache->put($tid, $tm);
                }
            } elseif (in_array($kind, ['write_rows', 'update_rows', 'delete_rows'], true)) {
                $rows = (array)($result['rows'] ?? []);
                $built = ChangeBuilder::build($cache, $kind, $rows, (int)($result['tableId'] ?? 0), $currentXid, $timestamp, $binlogFile, $binlogPos, $counter);
                if (is_array($built)) {
                    $changes = array_merge($changes, (array)($built['changes'] ?? []));
                    $counter = (int)($built['counter'] ?? $counter);
                }
            }
        }

        return json_encode([
            'ok' => true,
            'changes' => $changes,
            'warnings' => $warnings,
        ], JSON_FLAGS);
    } catch (\Throwable $e) {
        return json_encode([
            'ok' => false,
            'changes' => [],
            'warnings' => [(string)$e->getMessage()],
            'error' => (string)$e->getMessage(),
        ], JSON_FLAGS);
    }
}

#[WasmExport(name: 'generate-rollback')]
function generate_rollback(string $changes_json): string
{
    try {
        $changes = json_decode($changes_json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($changes)) {
            $changes = [];
        }
        $result = RollbackGenerator::generate($changes);
        return json_encode($result, JSON_FLAGS);
    } catch (\Throwable $e) {
        return json_encode([
            'ok' => false,
            'sql' => '',
            'stats' => ['statements' => 0, 'transactions' => 0],
            'error' => (string)$e->getMessage(),
        ], JSON_FLAGS);
    }
}

#[WasmExport(name: 'check-binlog-cfg')]
function check_binlog_cfg(string $meta_json): string
{
    try {
        $meta = json_decode($meta_json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($meta)) {
            $meta = [];
        }
        $result = CheckBinlogCfg::check($meta);
        return json_encode($result, JSON_FLAGS);
    } catch (\Throwable $e) {
        return json_encode([
            'ok' => false,
            'errors' => [['code' => 1005, 'message' => (string)$e->getMessage()]],
            'warnings' => [],
        ], JSON_FLAGS);
    }
}
