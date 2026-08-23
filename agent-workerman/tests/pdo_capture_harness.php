<?php
// 引导：后台跑假 MySQL 服务端 → 连 PDO → 读取服务端捕获的输出
$base = __DIR__;
$serverOut = tempnam(sys_get_temp_dir(), 'fakesrv');
$proc = proc_open(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/fake_mysql_server.php'),
    [1 => ['file', $serverOut, 'w'], 2 => ['file', $serverOut, 'a']],
    $pipes,
    $base,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($proc)) {
    echo "无法启动假服务端\n";
    exit(1);
}
// 等监听就绪
usleep(800_000);

try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3399;dbname=test', 'root', 'root', [
        PDO::ATTR_TIMEOUT => 5,
    ]);
    echo "PDO connected (unexpected)\n";
} catch (Throwable $e) {
    echo "PDO error: " . $e->getMessage() . PHP_EOL;
}

// 等服务端处理完
usleep(1_500_000);

// 清理
if (DIRECTORY_SEPARATOR === '\\') {
    $st = proc_get_status($proc);
    if ($st['running']) {
        exec('taskkill /F /T /PID ' . (int) $st['pid'] . ' 2>nul');
    }
}
proc_terminate($proc);

echo "--- fake server output ---\n";
echo (string) file_get_contents($serverOut);
@unlink($serverOut);
