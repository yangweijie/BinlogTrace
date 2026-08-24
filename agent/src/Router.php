<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * Router — 把 HTTP 请求路由到 AgentHandler
 *
 * 路由：
 *   POST /connect  新会话：测试连接 + 采集元数据，回 connected 帧
 *   POST /query    session 复用：只读查询，回 query-result 帧
 *   POST /dump     session 复用：启动 binlog 解析（SSE 长流）
 *   POST /close    销毁会话
 *
 * @param callable(string $body, array $headers, int $code): void $respondFn  写短响应
 * @param callable(AgentHandler $h): void $sseSetupFn                        进入 SSE（注入 sseWriter + 注册管道）
 */
final class Router
{
    public function handle(
        HttpRequest $req,
        callable $respondFn,
        callable $sseSetupFn
    ): void {
        $path = $req->path();
        $method = strtoupper($req->method);

        // 统一解包 v2 帧信封：客户端（前端 ws.ts）发送的是完整帧
        // {v,id,type,ts,payload}，而各 handler 期望拿到内层的 payload。
        // 若 body 已是裸 payload（无顶层 payload 字段）则原样透传，向后兼容。
        $raw = $req->json();
        $frameId = is_array($raw) ? (string) ($raw['id'] ?? '') : '';
        $payload = (is_array($raw) && isset($raw['payload']) && is_array($raw['payload']))
            ? $raw['payload']
            : $raw;

        // CORS（前端跨端口访问）
        $cors = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
        ];

        if ($method === 'OPTIONS') {
            ($respondFn)('', $cors, 204);
            return;
        }
        if ($method !== 'POST') {
            ($respondFn)(Frame::build('', 'error', ['code' => AgentConstants::PROTOCOL_ERROR, 'message' => '仅支持 POST']), $cors, 405);
            return;
        }

        switch ($path) {
            case '/connect':
                $handler = new AgentHandler();
                $handler->setResponder(function (string $json) use ($respondFn, $cors) {
                    ($respondFn)($json, $cors, 200);
                });
                $handler->handleConnect($frameId, $payload);
                return;

            case '/query':
            case '/dump':
            case '/close':
                $body = $payload;
                $token = (string) ($body['session'] ?? '');
                $handler = $token !== '' ? SessionManager::get($token) : null;
                if ($handler === null) {
                    ($respondFn)(Frame::build('', 'error', ['code' => AgentConstants::PROXY_NOT_READY, 'message' => '会话不存在或已过期，请先 connect']), $cors, 200);
                    return;
                }
                if ($path === '/close') {
                    $handler->setResponder(function (string $json) use ($respondFn, $cors) {
                        ($respondFn)($json, $cors, 200);
                    });
                    $handler->handleClose();
                    return;
                }
                // query / dump 都先确保 responder（dump 错误也可能走短响应兜底）
                $handler->setResponder(function (string $json) use ($respondFn, $cors) {
                    ($respondFn)($json, $cors, 200);
                });
                if ($path === '/dump') {
                    ($sseSetupFn)($handler); // 进入 SSE：注入 sseWriter + 注册管道
                    $handler->handleDump($frameId, $body);
                } else {
                    $handler->handleQuery($frameId, $body);
                }
                return;

            default:
                ($respondFn)(Frame::build('', 'error', ['code' => AgentConstants::PROTOCOL_ERROR, 'message' => "未知路由: {$path}"]), $cors, 404);
        }
    }
}
