<?php

namespace app\middleware;

use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 允许跨域（前端 Vite 5173 调 webman 8080）。
 * 仅放行预检 OPTIONS 与回写必要响应头；不缓存凭据来源白名单按需收紧。
 */
class Cors
{
    public function process(Request $request, callable $handler): Response
    {
        // 预检请求直接返回 204，避免进入业务处理
        if ($request->method() === 'OPTIONS') {
            $response = new Response(204);
        } else {
            $response = $handler($request);
        }

        $response->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET,POST,OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type,Authorization',
            'Access-Control-Max-Age' => '86400',
        ]);

        return $response;
    }
}
