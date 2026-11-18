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
        if ($json !== false) {
            return $json;
        }
        // 编码失败（如 payload 含非法 UTF-8）不能返回空串：那会变成 Content-Length: 0
        // 的空响应，客户端无从判断。回一帧字面 error（旧 webman 版的 1013 兜底）。
        $safeId = str_replace(['"', "\\", "\r", "\n"], '', $id);
        return '{"v":' . AgentConstants::PROTOCOL_VERSION . ',"id":"' . $safeId
            . '","type":"error","ts":' . self::now()
            . ',"payload":{"code":' . AgentConstants::META_MISSING . ',"message":"encode error"}}';
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
