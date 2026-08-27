<?php

declare(strict_types=1);

namespace App\Controller;

use DmsAgent\SessionManager;
use DmsAgent\WsHandler;
use support\Request;
use Webman\Http\Response;
use Workerman\Connection\TcpConnection;

/**
 * Agent HTTP 控制器：将各路由委托给 DmsAgent\WsHandler（真实 binlog 代理逻辑）。
 *
 * connect/query/close 为同步响应，控制器直接返回 WsHandler 生成的 Response；
 * dump 为 SSE 长流，由 onDump 返回 Transfer-Encoding: chunked 的响应头，
 * 后续帧通过 $request->connection 以 Chunk 推送（webman 官方 SSE 方式）。
 */
class AgentController
{
    /** 解析请求体：优先用 $request->post()（webman 已解码 JSON body）；
     *  回退到 rawBody + json_decode。 */
    private function parseBody(Request $request): array
    {
        $post = $request->post();
        if (is_array($post) && !empty($post)) {
            return $post;
        }
        $body = $request->rawBody();
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private function getHandler(Request $request): ?WsHandler
    {
        // 前端将 session 放在 body.payload.session（与 connect/query/close/startDump 一致）
        $frame = $this->parseBody($request);
        $payload = is_array($frame['payload'] ?? null) ? $frame['payload'] : [];
        $token = (string) ($payload['session'] ?? '');
        return $token === '' ? null : SessionManager::get($token);
    }

    private function errorResponse(int $code, string $message): Response
    {
        return response()->withStatus(400)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody((string) json_encode([
                'v' => 2, 'id' => '', 'type' => 'error',
                'ts' => (int) (microtime(true) * 1000),
                'payload' => ['code' => $code, 'message' => $message],
            ], JSON_UNESCAPED_SLASHES));
    }

    public function index(): Response
    {
        return response('{"ok":true,"message":"BinlogTrace Agent (Webman HTTP mode)"}');
    }

    public function connect(Request $request): Response
    {
        // connect 为首个请求，payload 是 MySQL 连接参数（无 session），
        // 必须新建 handler 而非从 session 取（此时尚无 token）。
        $handler = new WsHandler();
        $frame = $this->parseBody($request);
        $frame['type'] = 'connect';
        return $handler->onConnect($request->connection, $frame);
    }

    public function query(Request $request): Response
    {
        return $this->dispatch($request, fn (WsHandler $h, TcpConnection $c, array $f) => $h->onQuery($c, $f))
            ?? $this->errorResponse(1003, '会话不存在或已过期');
    }

    public function close(Request $request): Response
    {
        return $this->dispatch($request, fn (WsHandler $h, TcpConnection $c, array $f) => $h->onCloseRequest($c, $f))
            ?? $this->errorResponse(1003, '会话不存在或已过期');
    }

    public function startDump(Request $request): Response
    {
        $frame = $this->parseBody($request);
        $payload = is_array($frame['payload'] ?? null) ? $frame['payload'] : [];
        $token = (string) ($payload['session'] ?? '');
        $handler = $token === '' ? null : SessionManager::get($token);
        if ($handler === null) {
            return $this->errorResponse(1003, '会话不存在或已过期');
        }
        $frame['type'] = 'binlog-dump';
        return $handler->onDump($request->connection, $frame);
    }

    private function dispatch(Request $request, callable $action): ?Response
    {
        $handler = $this->getHandler($request);
        if ($handler === null) {
            return null;
        }
        $frame = $this->parseBody($request);
        $frame['type'] = 'frame';
        return $action($handler, $request->connection, $frame);
    }
}
