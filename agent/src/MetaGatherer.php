<?php

declare(strict_types=1);

/**
 * MetaGatherer — MySQL binlog 元数据收集（SHOW MASTER STATUS / 变量 / 权限）
 * 供 ConnectionHandler 在 connect 成功后一次性调用
 */
final class MetaGatherer
{
    private Client $mysql;
    private int $serverId;

    public function __construct(Client $mysql, int $serverId)
    {
        $this->mysql = $mysql;
        $this->serverId = $serverId;
    }

    public function gatherBinlogMeta(): array
    {
        $result = $this->mysql->query('SHOW MASTER STATUS');
        $hasBinlog = false;
        $binlogFile = '';
        $binlogPos = 0;
        if ($result !== false && isset($result['rows'][0])) {
            $row = $result['rows'][0];
            $hasBinlog = true;
            $binlogFile = (string)$row[0];
            $binlogPos = (int)($row[1] ?? 0);
        }

        $format = $this->getVariable('binlog_format');
        $rowImage = $this->getVariable('binlog_row_image');
        $serverVersion = $this->getVariable('version');
        $privileges = $this->queryPrivileges();

        return [
            'ok' => true,
            'serverVersion' => $serverVersion,
            'binlogFile' => $hasBinlog ? $binlogFile : null,
            'binlogPos' => $hasBinlog ? $binlogPos : null,
            'binlogFormat' => $format,
            'binlogRowImage' => $rowImage,
            'hasBinlog' => $hasBinlog,
            'serverId' => $this->serverId,
            'userPrivileges' => $privileges,
        ];
    }

    public function getBinlogFile(): string
    {
        $result = $this->mysql->query('SHOW MASTER STATUS');
        if ($result !== false && isset($result['rows'][0][0])) {
            return (string)$result['rows'][0][0];
        }
        return '';
    }

    public function getBinlogPos(): int
    {
        $result = $this->mysql->query('SHOW MASTER STATUS');
        if ($result !== false && isset($result['rows'][0][1])) {
            return (int)$result['rows'][0][1];
        }
        return 0;
    }

    private function queryPrivileges(): array
    {
        $result = $this->mysql->query('SHOW GRANTS');
        $privileges = [];
        if ($result === false) {
            return $privileges;
        }
        foreach ($result['rows'] as $row) {
            $grant = (string)($row[0] ?? '');
            if (stripos($grant, 'SELECT') !== false) {
                $privileges[] = 'SELECT';
            }
            if (stripos($grant, 'REPLICATION SLAVE') !== false) {
                $privileges[] = 'REPLICATION SLAVE';
            }
            if (stripos($grant, 'REPLICATION CLIENT') !== false) {
                $privileges[] = 'REPLICATION CLIENT';
            }
        }
        return $privileges;
    }

    private function getVariable(string $name): string
    {
        $result = $this->mysql->query('SELECT @@' . $name);
        if ($result !== false && isset($result['rows'][0][0])) {
            return (string)$result['rows'][0][0];
        }
        return '';
    }
}