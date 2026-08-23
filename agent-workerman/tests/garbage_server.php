<?php

declare(strict_types=1);

/**
 * garbage_server.php — 冒烟测试辅助进程
 * 监听指定端口，接受任意连接并立即发送非 MySQL 握手字节（首字节非 0x0a），
 * 用于验证 agent 的握手解析失败路径（错误码 1010）。
 *
 * 用法：php garbage_server.php <port>
 */

$port = (int) ($argv[1] ?? 0);
if ($port <= 0) {
    fwrite(STDERR, "usage: php garbage_server.php <port>\n");
    exit(1);
}

$errno = 0;
$errstr = '';
$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "garbage_server bind fail: {$errstr}\n");
    exit(1);
}

// 最多存活 20s，接受 3 个连接（每次连接发送垃圾字节）
$deadline = microtime(true) + 20;
$accepted = 0;
while (microtime(true) < $deadline && $accepted < 3) {
    $c = @stream_socket_accept($server, 1);
    if ($c) {
        fwrite(STDOUT, 'accepted #' . ($accepted + 1) . PHP_EOL);
        @fwrite($c, "NOT A MYSQL HANDSHAKE \x00\x01\x02\x03");
        @fflush($c);
        @fclose($c);
        $accepted++;
    }
}
fclose($server);
exit(0);
