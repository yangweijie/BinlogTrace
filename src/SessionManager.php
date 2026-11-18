<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * SessionManager — 无连接 HTTP 模式下的会话映射
 * connect 时生成 token，后续 dump/query/close 带 token 取回同一 AgentHandler，复用 MySQL 连接与 dump 状态。
 *
 * HTTP 模式没有「连接关闭」事件，会话只能靠显式 close 释放；页面刷新/关标签会留下
 * 无人回收的 handler + PDO 连接，因此这里补两条回收路径：
 *   - Router 在 connect 时按前端上报的 prevSession 精确替换旧会话
 *   - sweepIdle：空闲超过 TTL（默认 30 分钟，DMS_SESSION_TTL 可覆盖）且未在 dump 的会话
 */
final class SessionManager
{
    private const DEFAULT_TTL_SEC = 1800;

    /** @var array<string, AgentHandler> */
    private static array $sessions = [];
    /** @var array<string, int> token -> 最后活跃时间（epoch 秒） */
    private static array $lastSeen = [];

    public static function create(AgentHandler $handler): string
    {
        $token = bin2hex(random_bytes(16));
        self::$sessions[$token] = $handler;
        self::$lastSeen[$token] = time();
        return $token;
    }

    public static function get(string $token): ?AgentHandler
    {
        if (!isset(self::$sessions[$token])) {
            return null;
        }
        self::$lastSeen[$token] = time();
        return self::$sessions[$token];
    }

    public static function remove(string $token): void
    {
        unset(self::$sessions[$token], self::$lastSeen[$token]);
    }

    public static function count(): int
    {
        return count(self::$sessions);
    }

    /** 会话空闲上限（秒）：环境变量 DMS_SESSION_TTL 可覆盖（便于测试与部署调优） */
    public static function ttlSec(): int
    {
        $raw = (string) (getenv('DMS_SESSION_TTL') ?: '');
        if ($raw === '') {
            $raw = (string) ($_ENV['DMS_SESSION_TTL'] ?? '');
        }
        $ttl = (int) $raw;
        return $ttl > 0 ? $ttl : self::DEFAULT_TTL_SEC;
    }

        /** 回收空闲超过 TTL 且未在 dump 的会话，返回回收数量 */
    public static function sweepIdle(int $ttlSec): int
    {
        $now = time();
        $dropped = 0;
        foreach (self::$sessions as $token => $handler) {
            $seen = self::$lastSeen[$token] ?? $now;
            if ($handler->isDumping()) {
                // 正在流式输出：不算空闲，刷新活跃时间
                self::$lastSeen[$token] = $now;
                continue;
            }
            if ($now - $seen < $ttlSec) {
                continue;
            }
            self::remove($token);
            $handler->destroySession();
            $dropped++;
        }
        return $dropped;
    }
}
