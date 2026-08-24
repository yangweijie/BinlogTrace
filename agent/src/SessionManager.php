<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * SessionManager — 无连接 HTTP 模式下的会话映射
 * connect 时生成 token，后续 dump/query/close 带 token 取回同一 AgentHandler，复用 MySQL 连接与 dump 状态。
 */
final class SessionManager
{
    /** @var array<string, AgentHandler> */
    private static array $sessions = [];

    public static function create(AgentHandler $handler): string
    {
        $token = bin2hex(random_bytes(16));
        self::$sessions[$token] = $handler;
        return $token;
    }

    public static function get(string $token): ?AgentHandler
    {
        return self::$sessions[$token] ?? null;
    }

    public static function remove(string $token): void
    {
        unset(self::$sessions[$token]);
    }
}
