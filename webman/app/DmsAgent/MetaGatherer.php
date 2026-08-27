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

    /** @var mixed 采集完成后的最终返回值（onDone 闭包产出，krowinski 同步驱动时由本字段回传） */
    private $finalResult = null;

    public function __construct(KrowinskiQueryAdapter $mysql, int $serverId)
    {
        $this->mysql = $mysql;
        $this->serverId = $serverId;
    }

    /** @return mixed 返回 $onDone 回调的返回值（connect 时即 connected 响应） */
    public function gather(callable $onDone)
    {
        $this->finalResult = null;
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
            ['SHOW BINARY LOG STATUS', 'master'],
            ['SHOW MASTER STATUS', 'master'],
            ['SELECT @@server_id', 'serverId'],
            ['SELECT @@binlog_format', 'binlogFormat'],
            ['SELECT @@binlog_row_image', 'binlogRowImage'],
            ['SELECT @@version', 'serverVersion'],
            ['SHOW GRANTS', 'grants'],
        ];
        $this->next();
        return $this->finalResult;
    }

    private function next(): mixed
    {
        if ($this->steps === []) {
            $done = $this->onDone;
            $this->onDone = null;
            if ($done !== null) {
                $this->finalResult = $done($this->meta);
                return $this->finalResult;
            }
            return null;
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
        // 同步驱动（krowinski 阻塞式）：回调内已递归 next() 并产出最终 Response，
        // 但本步的 query() 本身无返回值，需由 onDone 闭包回传；此处返回 null 仅占位，
        // 真正响应由最内层 next() 的 $done($meta) 返回。
        return null;
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
            if ($key === 'serverId') {
                $this->meta['serverId'] = (int) (array_values($firstRow)[0] ?? 0);
                return;
            }
            $this->meta[$key] = (string) (array_values($firstRow)[0] ?? '');
        }
    }
}
