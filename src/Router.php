<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * Router — 把 HTTP 请求路由到 AgentHandler
 *
 * 路由：
 *   GET  /ping     健康检查：返回服务状态（无需认证）
 *   POST /connect  新会话：测试连接 + 采集元数据，回 connected 帧
 *   POST /query    session 复用：只读查询，回 query-result 帧
 *   POST /dump     session 复用：启动 binlog 解析（SSE 长流）
 *   POST /close    销毁会话
 *
 * 发送统一经由注入的 ClientConn（writeSse / respond），避免不可编译的闭包回调。
 */
final class Router
{
    public function handle(
        HttpRequest $req,
        ClientConn $c
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

        $cors = $c->corsHeaders();

        if ($method === 'OPTIONS') {
            $c->respond('', $cors, 204);
            return;
        }
        if ($method === 'GET' && $path === '/ping') {
            $c->respond(json_encode([
                'ok' => true,
                'service' => 'binlog-agent',
                'version' => AgentConstants::VERSION,
                'ts' => time(),
                'uptime' => Server::uptimeSec(),
                'php' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'sessions' => SessionManager::count(),
            ], JSON_UNESCAPED_UNICODE), $cors, 200, 'application/json');
            return;
        }
        // 前端静态文件（dist/ 与二进制同目录）：/ 与 /assets/*；其余 GET 404
        if ($method === 'GET') {
            if ($path === '/' || $path === '/index.html') {
                $this->serveStatic($c, '/index.html', $cors);
                return;
            }
            if (strpos($path, '/assets/') === 0) {
                $this->serveStatic($c, $path, $cors);
                return;
            }
            $c->respond(Frame::build('', 'error', ['code' => AgentConstants::PROTOCOL_ERROR, 'message' => "未知路由: {$path}"]), $cors, 404);
            return;
        }
        if ($method !== 'POST') {
            $c->respond(Frame::build('', 'error', ['code' => AgentConstants::PROTOCOL_ERROR, 'message' => '仅支持 POST']), $cors, 405);
            return;
        }

        switch ($path) {
            case '/connect':
                $handler = new AgentHandler();
                $handler->setClient($c);
                $handler->handleConnect($frameId, $payload);
                return;

            case '/query':
            case '/dump':
            case '/close':
                $body = $payload;
                $token = (string) ($body['session'] ?? '');
                $handler = $token !== '' ? SessionManager::get($token) : null;
                if ($handler === null) {
                    $c->respond(Frame::build('', 'error', ['code' => AgentConstants::PROXY_NOT_READY, 'message' => '会话不存在或已过期，请先 connect']), $cors, 200);
                    return;
                }
                $handler->setClient($c);
                if ($path === '/close') {
                    $handler->handleClose();
                    return;
                }
                if ($path === '/dump') {
                    $c->beginSse();
                    $c->owner = $handler;
                    $handler->handleDump($frameId, $body);
                } else {
                    $handler->handleQuery($frameId, $body);
                }
                return;

            default:
                $c->respond(Frame::build('', 'error', ['code' => AgentConstants::PROTOCOL_ERROR, 'message' => "未知路由: {$path}"]), $cors, 404);
                return;
        }
    }

    /**
     * 静态文件服务（前端 SPA 产物，dist/ 与二进制同目录）。
     * 安全：拒绝 '..'、realpath 后必须仍在 dist 目录内。
     */
    private function serveStatic(ClientConn $c, string $urlPath, array $cors): void
    {
        if (strpos($urlPath, '..') !== false) {
            $c->respond(Frame::build('', 'error', ['code' => AgentConstants::INVALID_PARAM, 'message' => '非法路径']), $cors, 400);
            return;
        }
        $dist = Runtime::$binDir . '/dist';
        $fsPath = $dist . str_replace('/', DIRECTORY_SEPARATOR, $urlPath);
        $real = realpath($fsPath);
        $realDist = realpath($dist);
        if ($real === false || $realDist === false || strpos($real, $realDist) !== 0) {
            $c->respond(Frame::build('', 'error', ['code' => AgentConstants::INVALID_PARAM, 'message' => '文件不存在']), $cors, 404);
            return;
        }
        $data = file_get_contents($real);
        if ($data === false) {
            $c->respond(Frame::build('', 'error', ['code' => AgentConstants::INTERNAL_ERROR, 'message' => '文件读取失败']), $cors, 500);
            return;
        }
        $c->respond($data, $cors, 200, self::mimeOf($real));
    }

    private static function mimeOf(string $path): string
    {
        $dot = strrpos($path, '.');
        $ext = $dot === false ? '' : strtolower(substr($path, $dot + 1));
        switch ($ext) {
            case 'html':
            case 'htm':
                return 'text/html; charset=utf-8';
            case 'js':
            case 'mjs':
                return 'text/javascript; charset=utf-8';
            case 'css':
                return 'text/css; charset=utf-8';
            case 'svg':
                return 'image/svg+xml';
            case 'png':
                return 'image/png';
            case 'jpg':
            case 'jpeg':
                return 'image/jpeg';
            case 'gif':
                return 'image/gif';
            case 'ico':
                return 'image/x-icon';
            case 'json':
            case 'map':
                return 'application/json; charset=utf-8';
            case 'woff':
                return 'font/woff';
            case 'woff2':
                return 'font/woff2';
            case 'txt':
                return 'text/plain; charset=utf-8';
            case 'wasm':
                return 'application/wasm';
            default:
                return 'application/octet-stream';
        }
    }
}
