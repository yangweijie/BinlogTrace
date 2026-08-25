<?php

declare(strict_types=1);

namespace DmsAgent;

use DmsAgent\Mysql\PdoConnection;

/**
 * MetaGatherer — 采集 MySQL 元数据（协议 v2「connected」帧所需字段）。
 * 改用 PdoConnection（C++ 直连）查询，去掉对 PDO 对象与 pdo_mysql 扩展的依赖。
 */
final class MetaGatherer
{
    public function __construct(
        private PdoConnection $conn,
        private int $serverId
    ) {
    }

    /**
     * 采集元数据，返回 connected 帧 payload。
     * @return array{
     *     ok: bool,
     *     serverVersion: string,
     *     binlogFile: string|null,
     *     binlogPos: int|null,
     *     binlogFormat: string,
     *     binlogRowImage: string,
     *     hasBinlog: bool,
     *     serverId: int,
     *     userPrivileges: string[],
     * }
     */
    public function gather(): array
    {
        $version = $this->conn->serverVersion();
        $hasBinlog = $this->checkBinlogEnabled();

        $binlogFormat = $this->var('binlog_format') ?: 'UNKNOWN';
        $binlogRowImage = $this->var('binlog_row_image') ?: 'UNKNOWN';

        $binlogFile = null;
        $binlogPos = null;
        if ($hasBinlog) {
            $master = $this->queryRow('SHOW BINARY LOG STATUS')
                ?? $this->queryRow('SHOW MASTER STATUS');
            if (is_array($master)) {
                $binlogFile = $master['File'] ?? $master['file'] ?? null;
                $binlogPos = isset($master['Position']) ? (int) $master['Position'] : null;
            }
        }

        return [
            'ok' => true,
            'serverVersion' => (string) $version,
            'binlogFile' => $binlogFile,
            'binlogPos' => $binlogPos,
            'binlogFormat' => (string) $binlogFormat,
            'binlogRowImage' => (string) $binlogRowImage,
            'hasBinlog' => $hasBinlog,
            'serverId' => $this->serverId,
            'userPrivileges' => $this->privileges(),
        ];
    }

    private function checkBinlogEnabled(): bool
    {
        $row = $this->queryRow("SHOW VARIABLES LIKE 'log_bin'");
        if (!is_array($row)) {
            return false;
        }
        $val = strtoupper((string) ($row['Value'] ?? ''));
        return $val === 'ON' || $val === '1' || $val === 'TRUE';
    }

    /** @return array<string,string>|null */
    private function queryRow(string $sql): ?array
    {
        try {
            return $this->conn->queryRow($sql);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function var(string $name): ?string
    {
        $row = $this->queryRow("SHOW VARIABLES LIKE '{$name}'");
        if (!is_array($row)) {
            return null;
        }
        $val = $row['Value'] ?? null;
        return is_string($val) ? $val : null;
    }

    /** @return string[] */
    private function privileges(): array
    {
        try {
            return $this->conn->fetchColumn('SHOW GRANTS FOR CURRENT_USER', 0);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
