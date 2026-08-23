<?php

declare(strict_types=1);

namespace DmsAgent\Mysql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Krowinski-based PDO adapter for query-only connections.
 * Replaces AsyncClient for query path (connect/query part only);
 * binlog-dump remains on AsyncClient per user instruction.
 */
final class KrowinskiQueryAdapter
{
    private ?Connection $pdo = null;
    private bool $connected = false;

    public function connect(
        string $host,
        int $port,
        string $user,
        string $password,
        string $database,
        int $timeoutSec = 5
    ): bool {
        try {
            $params = [
                'driver' => 'pdo_mysql',
                'host' => $host,
                'port' => $port,
                'user' => $user,
                'password' => $password,
                'charset' => 'utf8mb4',
            ];
            if ($database !== '') {
                $params['dbname'] = $database;
            }
            $this->pdo = DriverManager::getConnection($params);
            $this->connected = $this->pdo !== null;
            if ($this->connected && $database !== '') {
                // 数据库已在连接参数中传入，此处无需额外 USE
            }
            return true;
        } catch (\Throwable $e) {
            $this->connected = false;
            $this->pdo = null;
            return false;
        }
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->pdo !== null;
    }

    public function close(): void
    {
        $this->connected = false;
        $this->pdo = null;
    }

    public function getCurrentDatabase(): ?string
    {
        return null; // 简化：不追踪当前库
    }

    /**
     * 同步执行只读查询，直接返回结果数组（适配 AsyncClient 的回调风格）
     */
    public function query(
        string $sql,
        callable $onResult,
        callable $onError
    ): void {
        if (!$this->isConnected() || $this->pdo === null) {
            $onError(1006, '查询连接未就绪');
            return;
        }
        try {
            $nativePdo = $this->pdo->getNativeConnection();
            $stmt = $nativePdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
            $columns = [];
            $colCount = $stmt->columnCount();
            for ($i = 0; $i < $colCount; $i++) {
                $meta = $stmt->getColumnMeta($i);
                $columns[] = [
                    'name' => (string) ($meta['name'] ?? 'col_' . $i),
                    'type' => (string) ($meta['sqlite:decl_type'] ?? ($meta['type'] ?? '')),
                ];
            }
            $onResult([
                'columns' => $columns,
                'rows' => $rows,
            ]);
        } catch (\Throwable $e) {
            $onError(1005, '查询失败: ' . $e->getMessage());
        }
    }
}
