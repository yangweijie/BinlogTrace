<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * Frame — 协议 v2 帧构造（与前端 ws.ts 解析对齐）
 * 单帧结构：{ v, id, type, ts, payload }
 */
final class Frame
{
    public static function build(string $id, string $type, array $payload): string
    {
        $frame = [
            'v' => AgentConstants::PROTOCOL_VERSION,
            'id' => $id,
            'type' => $type,
            'ts' => self::now(),
            'payload' => $payload,
        ];
        $json = json_encode($frame, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '' : $json;
    }

    /** SSE 格式：data: {json}\n\n */
    public static function sse(string $id, string $type, array $payload): string
    {
        return "data: " . self::build($id, $type, $payload) . "\n\n";
    }

    public static function now(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
