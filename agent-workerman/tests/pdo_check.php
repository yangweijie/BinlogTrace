<?php
// 验证 root/root 凭据（PDO 自带 caching_sha2 RSA 支持）
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=shengyibao;charset=utf8mb4', 'root', 'root', [
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $r = $pdo->query('SELECT 1 AS n');
    echo 'PDO OK: ' . $r->fetchColumn() . PHP_EOL;
    $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    echo 'tables: ' . count($rows) . PHP_EOL;
} catch (Throwable $e) {
    echo 'PDO FAIL: ' . $e->getMessage() . PHP_EOL;
}
