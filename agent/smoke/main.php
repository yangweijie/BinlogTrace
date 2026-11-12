<?php

declare(strict_types=1);

/**
 * rung-4 冒烟：验证 TypePHP 二进制运行时对本项目所需能力的实际支持。
 * 一次编译运行，回答所有"理论可用"问题：
 *   1) pdo / pdo_mysql 驱动是否可用（TP-AOT-021 是否已解决）
 *   2) stream_socket_server / stream_select / proc_open 是否存在且可工作
 *   3) PHP_BINARY / __DIR__ / getcwd() 在二进制中的取值（.env 定位依据）
 *   4) getenv / bin2hex / random_bytes / json_encode 基础函数
 */

function checkExt(string $name): void
{
    $ok = extension_loaded($name);
    echo "ext {$name} = " . ($ok ? 'true' : 'false') . "\n";
}

function checkFn(string $name): void
{
    echo "fn  {$name} = " . (function_exists($name) ? 'true' : 'false') . "\n";
}

function main(int $argc, array $argv): void
{
    echo "=== typephp runtime smoke ===\n";
    echo "PHP_VERSION = " . PHP_VERSION . "\n";
    echo "PHP_SAPI    = " . PHP_SAPI . "\n";
    echo "PHP_BINARY  = " . PHP_BINARY . "\n";
    echo "__DIR__     = " . __DIR__ . "\n";
    echo "getcwd()    = " . getcwd() . "\n";
    echo "argv0       = " . ($argv[0] ?? '') . "\n";

    checkExt('pdo');
    checkExt('pdo_mysql');
    checkExt('sockets');
    checkFn('stream_socket_server');
    checkFn('stream_select');
    checkFn('proc_open');
    checkFn('proc_get_status');
    checkFn('getenv');
    checkFn('bin2hex');
    checkFn('random_bytes');
    checkFn('stream_set_blocking');

    // 1) PDO mysql 驱动实际构造
    if (!extension_loaded('pdo_mysql')) {
        echo "enable_dl = " . (ini_get('enable_dl') ?: '(empty)') . "\n";
        echo "php_ini_loaded_file = " . (php_ini_loaded_file() ?: '(none)') . "\n";
        echo "extension_dir = " . (ini_get('extension_dir') ?: '(empty)') . "\n";
        // 尝试 dl() 动态加载发行版自带的 php_pdo_mysql.dll（不带 @，取错误信息）
        $extDir = 'D:/git/php/tpc_v0.9.5_windows_x64/ext';
        @ini_set('extension_dir', $extDir);
        echo "extension_dir after set = " . (ini_get('extension_dir') ?: '(empty)') . "\n";
        $ok = dl('php_pdo_mysql.dll');
        echo "dl(php_pdo_mysql.dll) = " . ($ok ? 'true' : 'false') . "\n";
        $last = error_get_last();
        if ($last !== null && isset($last['message'])) {
            echo "last error: " . $last['message'] . "\n";
        }
        echo "pdo_mysql after dl = " . (extension_loaded('pdo_mysql') ? 'true' : 'false') . "\n";
    }
    if (extension_loaded('pdo_mysql')) {
        try {
            $pdo = new \PDO('mysql:host=127.0.0.1;port=1;charset=utf8mb4', 'x', 'x', [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 1,
            ]);
            echo "pdo mysql construct = ok\n";
        } catch (\PDOException $e) {
            // 驱动在 → 连接失败是预期（没有真实 MySQL）；驱动不在 → "could not find driver"
            $msg = $e->getMessage();
            echo "pdo mysql construct threw (driver present?): " . $msg . "\n";
        } catch (\Throwable $e) {
            echo "pdo other throwable: " . get_class($e) . ": " . $e->getMessage() . "\n";
        }
    } else {
        echo "pdo mysql construct = SKIPPED (driver missing)\n";
    }

    // 2) stream_socket_server 实际监听
    $errno = 0;
    $errstr = '';
    $srv = @stream_socket_server('tcp://127.0.0.1:19871', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
    if (is_resource($srv)) {
        stream_set_blocking($srv, false);
        echo "stream_socket_server = ok (19871)\n";
        $r = [$srv];
        $w = [];
        $x = [];
        $n = @stream_select($r, $w, $x, 0, 100000);
        echo "stream_select = ok (n={$n})\n";
        fclose($srv);
    } else {
        echo "stream_socket_server = FAIL ({$errno}) {$errstr}\n";
    }

    // 3) proc_open 实际起子进程
    $pipes = [];
    $proc = @proc_open('cmd /c echo smoke-proc-ok', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($proc)) {
        $out = (string) @stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        echo "proc_open = ok (out=" . trim($out) . ")\n";
    } else {
        echo "proc_open = FAIL\n";
    }

    // 4) .env 定位依据
    $binDir = dirname(PHP_BINARY);
    $envPath = $binDir . DIRECTORY_SEPARATOR . '.env';
    echo "env candidate path = {$envPath} exists=" . (is_file($envPath) ? 'true' : 'false') . "\n";

    // 5) 基础函数
    echo "token = " . bin2hex(random_bytes(4)) . "\n";
    echo "json = " . (json_encode(['ok' => true, 'n' => 1], JSON_UNESCAPED_UNICODE) ?: 'FAIL') . "\n";
    echo "=== smoke done ===\n";
}
