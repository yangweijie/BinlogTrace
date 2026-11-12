<?php

declare(strict_types=1);

namespace DmsAgent\Mysql;

/**
 * PdoConnection — 纯 PDO MySQL 连接（跨平台，无 workerman / doctrine 依赖）
 */
final class PdoConnection
{
    private ?\PDO $pdo = null;
    private string $database = '';

    public function connect(string $host, int $port, string $user, string $password, string $database, int $timeoutSec): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;%s;charset=utf8mb4',
            $host,
            $port,
            $database !== '' ? 'dbname=' . $database : ''
        );
        $opts = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => $timeoutSec,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ];
        $this->pdo = new \PDO($dsn, $user, $password, $opts);
        $this->database = $database;
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('未连接');
        }
        return $this->pdo;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function database(): string
    {
        return $this->database;
    }

    /**
     * 执行只读查询，返回列定义 + 行数据。
     * @return array{ columns: array<int, array{ name: string, type: string }>, rows: array<int, array<string, mixed>> }
     */
    public function query(string $sql): array
    {
        $pdo = $this->pdo();
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return ['columns' => [], 'rows' => []];
        }
        $colCount = $stmt->columnCount();
        $columns = [];
        for ($i = 0; $i < $colCount; $i++) {
            $meta = $stmt->getColumnMeta($i);
            $columns[] = [
                'name' => (string) ($meta['name'] ?? 'col' . $i),
                'type' => (string) ($meta['native_type'] ?? ($meta['type'] ?? '')),
            ];
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            $rows = [];
        }
        return ['columns' => $columns, 'rows' => $rows];
    }

    public function close(): void
    {
        $this->pdo = null;
    }
}
