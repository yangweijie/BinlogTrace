<?php

declare(strict_types=1);

/**
 * TypePHP 二进制入口（mode: bin）。
 * 替代纯 PHP 的 bin/agent（getopt + require + class_exists 不可被编译）。
 * 编译：php bin/tpc.php agent/project.yml
 * 运行：./binlog-agent serve [--host <ip>] [--port <port>]
 */

use DmsAgent\Server;

function main(int $argc, array $argv): void
{
    $host = '127.0.0.1';
    $port = 8080;

    $args = array_slice($argv, 1);
    $cmd = $args[0] ?? 'serve';

    if ($cmd === 'help' || $cmd === '--help' || $cmd === '-h') {
        echo "DMS Agent — 轻量 MySQL binlog 追踪代理（原生 HTTP 服务）\n\n";
        echo "用法:\n";
        echo "  binlog-agent serve [--host <ip>] [--port <port>]   启动 HTTP 服务（默认 127.0.0.1:8080）\n";
        echo "  binlog-agent help                                 显示本帮助\n\n";
        echo "路由（前端对接）:\n";
        echo "  POST /connect   测试连接 + 采集元数据\n";
        echo "  POST /query     session 只读查询\n";
        echo "  POST /dump      session 启动 binlog 解析（SSE）\n";
        echo "  POST /close     销毁会话\n";
        return;
    }

    if ($cmd !== 'serve') {
        fwrite(STDERR, "未知命令: {$cmd}\n");
        exit(1);
    }

    for ($i = 1; $i < count($args); $i++) {
        if ($args[$i] === '--host' && isset($args[$i + 1])) {
            $host = $args[++$i];
        } elseif ($args[$i] === '--port' && isset($args[$i + 1])) {
            $port = (int) $args[++$i];
        }
    }

    $server = new Server($host, $port);
    $server->run();
}
