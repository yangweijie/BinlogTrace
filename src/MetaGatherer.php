<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * MetaGatherer — 用纯 PDO 采集 MySQL 元数据（协议 v2「connected」帧所需字段）
 * 对应原 agent-workerman 的 MetaGatherer，但去掉 workerman/krowinski 适配器依赖，改为同步 PDO。
 */
final class MetaGatherer
{
    public function __construct(
        private \PDO $pdo,
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
        $version = $this->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION) ?: '';
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
            // 真实 server_id 优先（旧版有 SELECT @@server_id）；查不到才回退客户端上报值
            'serverId' => $this->realServerId(),
            'userPrivileges' => $this->privileges(),
        ];
    }

    private function realServerId(): int
    {
        $row = $this->queryRow('SELECT @@server_id AS sid');
        if (is_array($row) && isset($row['sid'])) {
            return (int) $row['sid'];
        }
        return $this->serverId;
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
            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                return null;
            }
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
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
            $stmt = $this->pdo->query('SHOW GRANTS FOR CURRENT_USER');
            if ($stmt === false) {
                return [];
            }
            $privs = [];
            while ($g = $stmt->fetchColumn(0)) {
                if (is_string($g)) {
                    $privs[] = $g;
                }
            }
            return $privs;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
