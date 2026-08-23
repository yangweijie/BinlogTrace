<?php

declare(strict_types=1);

/**
 * AgentMain — WS TCP 代理入口（TypePHP 原生二进制模式）
 * 监听本地端口，每个浏览器连接一个 ConnectionHandler
 * 用法：./binlog-agent [--port 8080]
 */
function main(int $argc, array $argv): void
{
    $port = AgentConfig::port($argc, $argv);

    $server = @stream_socket_server('tcp://0.0.0.0:' . $port, $errno, $errstr);
    if ($server === false) {
        echo 'binlog-agent: 启动失败 (' . $errstr . ')' . PHP_EOL;
        return;
    }
    echo 'binlog-agent: 监听 0.0.0.0:' . $port . PHP_EOL;

    while (true) {
        $conn = stream_socket_accept($server, 5);
        if ($conn === false) {
            continue;
        }
        try {
            $handler = new ConnectionHandler($conn);
            $handler->run();
        } catch (\Throwable $e) {
            echo 'agent: handler exception: ' . $e->getMessage() . PHP_EOL;
        }
        try {
            fclose($conn);
        } catch (\Throwable $e) {
            echo 'agent: fclose conn error: ' . $e->getMessage() . PHP_EOL;
        }
    }
}