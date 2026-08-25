<?php

declare(strict_types=1);

namespace DmsAgent\Mysql;

/**
 * PdoConnection — 通过 C++ libmysqlclient 直连 MySQL（绕开 PDO/pdo_mysql 扩展）。
 * 内部调用 mysqlbox_*.cc（见 mysqlbox.cc）实现连接与查询，避免 Windows tpc 下
 * 缺失 pdo_mysql 驱动（could not find driver）的问题。
 */
final class PdoConnection
{
    /** @var mixed|null C++ MySQLBox 资源 */
    private $box = null;
    private string $database = '';

    public function connect(string $host, int $port, string $user, string $password, string $database, int $timeoutSec): void
    {
        try {
            $box = mysqlbox_connect($host, $port, $user, $password, $database, $timeoutSec);
        } catch (\Throwable $e) {
            $extDir = (string) ini_get('extension_dir');
            $loaded = function_exists('get_loaded_extensions') ? implode(',', get_loaded_extensions()) : '(n/a)';
            $msg = 'MySQL 连接失败: ' . $e->getMessage()
                . ' | ext_dir=' . $extDir
                . ' loaded=' . $loaded
                . ' driver=C++ libmysqlclient (no pdo_mysql required)';
            throw new \RuntimeException($msg, 0, $e);
        }
        if ($box === false || $box === null) {
            throw new \RuntimeException('MySQL 连接失败: mysqlbox_connect returned false');
        }
        $this->box = $box;
        $this->database = $database;
    }

    /** @return mixed|null */
    public function box()
    {
        return $this->box;
    }

    public function isConnected(): bool
    {
        return $this->box !== null;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function serverVersion(): string
    {
        if ($this->box === null) {
            return '';
        }
        return (string) mysqlbox_server_version($this->box);
    }

    /**
     * 执行只读查询，返回列定义 + 行数据。
     * @return array{ columns: array<int, array{ name: string, type: string }>, rows: array<int, array<string, mixed>> }
     */
    public function query(string $sql): array
    {
        if ($this->box === null) {
            throw new \RuntimeException('未连接');
        }
        return mysqlbox_query($this->box, $sql);
    }

    /**
     * 取查询首行（关联数组）。
     * @return array<string, mixed>|null
     */
    public function queryRow(string $sql): ?array
    {
        $result = $this->query($sql);
        $rows = $result['rows'] ?? [];
        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    /**
     * 取查询某列的所有值。
     * @return array<int, mixed>
     */
    public function fetchColumn(string $sql, int $col = 0): array
    {
        $result = $this->query($sql);
        $rows = $result['rows'] ?? [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $keys = array_keys($row);
            if (isset($keys[$col])) {
                $out[] = $row[$keys[$col]];
            }
        }
        return $out;
    }

    public function close(): void
    {
        if ($this->box !== null) {
            mysqlbox_close($this->box);
            $this->box = null;
        }
    }
}
