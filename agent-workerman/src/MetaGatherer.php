<?php

declare(strict_types=1);

namespace DmsAgent;

use DmsAgent\Mysql\KrowinskiQueryAdapter;

/**
 * MetaGatherer — 连接成功后异步采集 binlog 元数据
 * 与 agent/src/MetaGatherer.php（TypePHP 同步版）功能对齐，改为回调链（异步查询）
 */
final class MetaGatherer
{
    private KrowinskiQueryAdapter $mysql;
    private int $serverId;

    /** @var array<string, mixed> */
    private array $meta = [];

    /** @var array<int, array{0:string, 1:string}> [sql, 结果键] */
    private array $steps = [];

    /** @var callable|null (array $meta) */
    private $onDone = null;

    public function __construct(KrowinskiQueryAdapter $mysql, int $serverId)
    {
        $this->mysql = $mysql;
        $this->serverId = $serverId;
    }

    public function gather(callable $onDone): void
    {
        $this->meta = [
            'ok' => true,
            'serverVersion' => '',
            'binlogFile' => null,
            'binlogPos' => null,
            'binlogFormat' => '',
            'binlogRowImage' => '',
            'hasBinlog' => false,
            'serverId' => $this->serverId,
            'userPrivileges' => [],
        ];
        $this->onDone = $onDone;
        $this->steps = [
            ['SHOW MASTER STATUS', 'master'],
            ['SELECT @@binlog_format', 'binlogFormat'],
            ['SELECT @@binlog_row_image', 'binlogRowImage'],
            ['SELECT @@version', 'serverVersion'],
            ['SHOW GRANTS', 'grants'],
        ];
        $this->next();
    }

    private function next(): void
    {
        if ($this->steps === []) {
            $done = $this->onDone;
            $this->onDone = null;
            if ($done !== null) {
                $done($this->meta);
            }
            return;
        }
        [$sql, $key] = array_shift($this->steps);
        $self = $this;
        $this->mysql->query(
            $sql,
            function (array $result) use ($self, $key): void {
                $self->applyResult($key, $result);
                $self->next();
            },
            function (int $code, string $message) use ($self): void {
                // 单条元数据查询失败不阻断整体采集
                $self->next();
            }
        );
    }

    private function applyResult(string $key, array $result): void
    {
        $rows = $result['rows'] ?? [];
        $firstRow = $rows[0] ?? null;

        if ($key === 'master') {
            if (is_array($firstRow)) {
                $firstRow = array_values($firstRow);
                $this->meta['hasBinlog'] = true;
                $this->meta['binlogFile'] = (string) ($firstRow[0] ?? '');
                $this->meta['binlogPos'] = (int) ($firstRow[1] ?? 0);
            }
            return;
        }

        if ($key === 'grants') {
            $privileges = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $grant = (string) (array_values($row)[0] ?? '');
                if (stripos($grant, 'ALL PRIVILEGES') !== false) {
                    // 该用户拥有全部权限，直接添加所需三项
                    $privileges[] = 'SELECT';
                    $privileges[] = 'REPLICATION SLAVE';
                    $privileges[] = 'REPLICATION CLIENT';
                    continue;
                }
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
            $this->meta['userPrivileges'] = array_values(array_unique($privileges));
            return;
        }

        if (is_array($firstRow)) {
            $this->meta[$key] = (string) (array_values($firstRow)[0] ?? '');
        }
    }
}
