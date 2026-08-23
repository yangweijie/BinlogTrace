<?php
// 连到假 MySQL 服务端，触发 mysqlnd 的 caching_sha2 完整认证
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3399;dbname=test', 'root', 'root', [
        PDO::ATTR_TIMEOUT => 5,
    ]);
    echo "PDO connected (unexpected)\n";
} catch (Throwable $e) {
    echo "PDO error: " . $e->getMessage() . PHP_EOL;
}
