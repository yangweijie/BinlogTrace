<?php

declare(strict_types=1);

/**
 * TP-AOT-021 — 最小复现：TypePHP AOT 原生二进制中 pdo_mysql 扩展无法加载。
 *
 * 仅做两件事：
 *   1) 打印 pdo_mysql 扩展加载诊断（与 AgentHandler::diagnosticInfo 同口径）；
 *   2) 尝试 `new \PDO('mysql:host=127.0.0.1;port=3306')` 触发驱动解析。
 *
 * 不需要真实 MySQL 实例即可复现：
 *   - 若 pdo_mysql 未静态内建进 php8ts.lib，则 extension_loaded 返回 false，
 *     且 new PDO('mysql:...') 抛出 "could not find driver"（或 Class PDO 解析失败）。
 *   - Zend PHP（扩展动态 .dll/.so 正常加载）应得到 extension_loaded=true 且 PDO 构造成功/给出正常连接错误。
 */

function diagnostic(): array
{
    return [
        'pdo' => extension_loaded('pdo'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'mysqlnd' => extension_loaded('mysqlnd'),
        'php_version' => PHP_VERSION,
        'zts' => defined('PHP_ZTS') ? (bool) PHP_ZTS : false,
        'sapi' => PHP_SAPI,
    ];
}

function main(): void
{
    echo "=== TP-AOT-021 pdo_mysql load diagnostic ===\n";
    $d = diagnostic();
    foreach ($d as $k => $v) {
        echo sprintf("  %-12s = %s\n", $k, var_export($v, true));
    }

    echo "\n--- try new \\PDO('mysql:host=127.0.0.1;port=3306') ---\n";
    try {
        $pdo = new \PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4');
        echo "PDO mysql constructed OK (driver present)\n";
    } catch (\Throwable $e) {
        echo 'PDO construct FAILED: ' . $e->getMessage() . "\n";
        echo 'exception: ' . get_class($e) . "\n";
    }
}

main();
