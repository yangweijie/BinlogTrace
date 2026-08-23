<?php

declare(strict_types=1);

use DmsAgent\WsHandler;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Websocket;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

/**
 * DMS Agent（Workerman 版）入口 — WebSocket TCP 代理，纯 PHP 运行，无需 TypePHP 编译器
 *
 * 用法：
 *   php start.php start                # 前台启动（Windows / Linux 均支持）
 *   php start.php start -d             # 守护进程模式（仅 Linux）
 *   php start.php stop | restart       # 管理命令（仅 Linux 守护模式）
 *   php start.php --port 9090          # 自定义端口（默认 8080）
 *
 * 与前端契约：协议 v2，帧 {v,id,type,ts,payload}，见 frontend/src/lib/ws.ts
 */

$port = 8080;
$rawArgv = $argv ?? [];
$argc = count($rawArgv);
for ($i = 0; $i < $argc; $i++) {
    if ($rawArgv[$i] === '--port' && isset($rawArgv[$i + 1]) && is_numeric($rawArgv[$i + 1])) {
        $port = (int) $rawArgv[$i + 1];
        break;
    }
}

$worker = new Worker('websocket://0.0.0.0:' . $port);
$worker->name = 'dms-agent';
// 单进程（Windows 下 workerman 本就只支持单进程；binlog 追踪为每连接独立状态）
$worker->count = 1;

$worker->onWorkerStart = function () use ($port): void {
    echo '[agent-workerman] 监听 websocket://0.0.0.0:' . $port . ' (协议 v2)' . PHP_EOL;
    echo '[agent-workerman] 提示: 无内置认证，仅限内网使用' . PHP_EOL;
};

$worker->onConnect = function (TcpConnection $conn): void {
    // 增大发送缓冲，避免大 binlog 事件 + 慢客户端时丢帧
    $conn->maxSendBufferSize = 64 * 1024 * 1024;
    // 出站一律 text 帧（默认即 BLOB，显式声明避免后续被改写）
    $conn->websocketType = Websocket::BINARY_TYPE_BLOB;
    $conn->context->handler = new WsHandler($conn);
};

$worker->onMessage = function (TcpConnection $conn, string $data): void {
    $handler = $conn->context->handler ?? null;
    if ($handler instanceof WsHandler) {
        $handler->onMessage($data);
    }
};

$worker->onClose = function (TcpConnection $conn): void {
    $handler = $conn->context->handler ?? null;
    if ($handler instanceof WsHandler) {
        $handler->onClose();
        $conn->context->handler = null;
    }
};

Worker::runAll();
