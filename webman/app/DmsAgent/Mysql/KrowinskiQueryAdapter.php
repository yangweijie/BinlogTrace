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
                // PHP 8.5 的 pdo_mysql 原生支持 caching_sha2_password，无需额外插件选项。
                'serverVersion' => '8.0',
            ];
            if ($database !== '') {
                $params['dbname'] = $database;
            }
            $this->pdo = DriverManager::getConnection($params);
            // Doctrine 的 getConnection() 是懒连接，不会立即与 MySQL 握手认证，
            // 真正认证延迟到首次执行 SQL。此处显式执行一次轻查询强制建连，
            // 让认证/选库错误在此抛出（返回 false → 1001），而非被后续元数据查询
            // 的 error 回调吞掉、误导为「未检测到 Binlog」。
            // 注意：Connection::connect() 为 protected，不能用；用 fetchOne 触发真实连接。
            $this->pdo->fetchOne('SELECT 1');
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
     * 同步执行只读查询。回调（onResult/onError）会返回响应对象，
     * 此处把回调的返回值继续向上传递，供调用方（execQuery）作为响应返回，
     * 否则同步查询会丢失响应、被上层当作“未处理”而回 1003。
     * @return mixed 回调产生的响应（Response），连接未就绪/异常时为 null
     */
    public function query(
        string $sql,
        callable $onResult,
        callable $onError
    ): mixed {
        if (!$this->isConnected() || $this->pdo === null) {
            return $onError(1006, '查询连接未就绪');
        }
        try {
            $nativePdo = $this->pdo->getNativeConnection();
            $stmt = $nativePdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $columns = [];
            $colCount = $stmt->columnCount();
            for ($i = 0; $i < $colCount; $i++) {
                $meta = $stmt->getColumnMeta($i);
                $columns[] = [
                    'name' => (string) ($meta['name'] ?? 'col_' . $i),
                    'type' => (string) ($meta['sqlite:decl_type'] ?? ($meta['type'] ?? '')),
                ];
            }
            return $onResult([
                'columns' => $columns,
                'rows' => $rows,
            ]);
        } catch (\Throwable $e) {
            return $onError(1005, '查询失败: ' . $e->getMessage());
        }
    }
}
