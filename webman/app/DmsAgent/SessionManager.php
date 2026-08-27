<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * SessionManager — HTTP 模式下的会话映射
 * 原 WebSocket 模型下每个浏览器 WS 连接持有一个 WsHandler（存在 $conn->context）；
 * HTTP 无连接，改用 session token 关联：connect 时生成 token，后续请求（dump/query/close）带 token 取回 WsHandler。
 */
final class SessionManager
{
    /** @var array<string, WsHandler> */
    private static array $sessions = [];

    public static function create(WsHandler $handler): string
    {
        $token = bin2hex(random_bytes(16));
        self::$sessions[$token] = $handler;
        return $token;
    }

    public static function get(string $token): ?WsHandler
    {
        return self::$sessions[$token] ?? null;
    }

    public static function remove(string $token): void
    {
        unset(self::$sessions[$token]);
    }

    public static function count(): int
    {
        return count(self::$sessions);
    }
}
