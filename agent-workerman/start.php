<?php

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use DmsAgent\SessionManager;
use DmsAgent\WsHandler;

$port = (int) ($_SERVER['argv'][2] ?? 8080);
if (!is_numeric($port) || $port < 1 || $port > 65535) {
    $port = 8080;
}

// HTTP 协议：Workerman 会把每个请求解析为 Request 对象传入 onMessage 的第二个参数。
// 不再依赖 WebSocket 升级握手。
$worker = new Worker("http://0.0.0.0:{$port}");

$worker->count = 1;

function jsonResponse(array $data, int $status = 200): Response
{
    return new Response($status, [
        'Content-Type' => 'application/json; charset=utf-8',
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'POST, GET, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type',
    ], json_encode($data, JSON_UNESCAPED_SLASHES));
}

$worker->onMessage = function (TcpConnection $connection, Request $request) {
    $path = trim($request->path(), '/');
    $method = strtoupper($request->method());

    // CORS 预检
    if ($method === 'OPTIONS') {
        $connection->send(new Response(204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
        ]));
        return;
    }

    if ($method !== 'POST') {
        $connection->send(jsonResponse(['ok' => false, 'error' => ['code' => 1010, 'message' => '仅支持 POST']], 405));
        return;
    }

    $raw = (string) $request->rawBody();
    $frame = json_decode($raw, true);
    if (!is_array($frame) || !isset($frame['type'])) {
        $connection->send(jsonResponse(['ok' => false, 'error' => ['code' => 1010, 'message' => '无效的帧']], 400));
        return;
    }

    switch ($path) {
        case 'connect':
            // connect 新建会话并自注册到 SessionManager，connected 帧回传 session token
            $handler = new WsHandler();
            $handler->onConnect($connection, $frame);
            break;
        case 'dump':
        case 'query':
        case 'close': {
            $token = (string) ($frame['payload']['session'] ?? '');
            $handler = SessionManager::get($token);
            if ($handler === null) {
                $connection->send(jsonResponse(['ok' => false, 'error' => ['code' => 1006, 'message' => '会话不存在或已失效，请重新连接']], 404));
                break;
            }
            if ($path === 'dump') {
                $handler->onDump($connection, $frame);
            } elseif ($path === 'query') {
                $handler->onQuery($connection, $frame);
            } else {
                $handler->onCloseRequest($connection, $frame);
            }
            break;
        }
        default:
            $connection->send(jsonResponse(['ok' => false, 'error' => ['code' => 1010, 'message' => "未知路由: {$path}"]], 404));
            break;
    }
};

Worker::runAll();
