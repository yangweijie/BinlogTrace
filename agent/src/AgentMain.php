<?php

declare(strict_types=1);

use DmsAgent\Server;

/**
 * 编译入口：tpc mode: bin 要求的全局 function main。
 * 启动逻辑从 bin/agent 搬入此处（bin/agent 仅用于纯 PHP 运行）。
 */
function main(int $argc, array $argv): void
{
    $args = array_slice($argv, 1);
    $cmd = $args[0] ?? 'help';

    if ($cmd === 'help' || $cmd === '--help' || $cmd === '-h') {
        fwrite(STDOUT, "DMS Agent — 轻量 MySQL binlog 追踪代理（原生 HTTP 服务）\n\n");
        fwrite(STDOUT, "用法:\n");
        fwrite(STDOUT, "  binlog-agent serve [--host <ip>] [--port <port>]   启动 HTTP 服务（默认 127.0.0.1:8080）\n");
        fwrite(STDOUT, "  binlog-agent help                                显示本帮助\n\n");
        fwrite(STDOUT, "路由（前端对接）:\n");
        fwrite(STDOUT, "  POST /connect   测试连接 + 采集元数据，返回 connected 帧（含 session）\n");
        fwrite(STDOUT, "  POST /query     session 只读查询\n");
        fwrite(STDOUT, "  POST /dump      session 启动 binlog 解析（SSE 长流）\n");
        fwrite(STDOUT, "  POST /close     销毁会话\n");
        return;
    }

    if ($cmd !== 'serve') {
        fwrite(STDERR, "未知命令: {$cmd}\n");
        fwrite(STDERR, "用法: binlog-agent serve [--host <ip>] [--port <port>]\n");
        exit(1);
    }

    $host = '127.0.0.1';
    $port = 8080;
    $n = count($args);
    for ($i = 1; $i < $n; $i++) {
        if ($args[$i] === '--host' && isset($args[$i + 1])) {
            $host = $args[++$i];
        } elseif ($args[$i] === '--port' && isset($args[$i + 1])) {
            $port = (int) $args[++$i];
        }
    }

    $server = new Server($host, $port);
    $server->run();
}
