<?php

declare(strict_types=1);

/**
 * agent/project.yml — WS TCP 代理（TypePHP 原生二进制模式）
 * 编译：php bin/tpc.php app/agent/ -o binlog-agent
 * 运行：./binlog-agent [--port 8080]
 */
final class AgentConfig
{
    public static function port(int $argc, array $argv): int
    {
        for ($i = 1; $i < $argc; $i++) {
            if ($argv[$i] === '--port' && isset($argv[$i + 1])) {
                return (int)$argv[$i + 1];
            }
        }
        return 8080;
    }
}

/**
 * WS 代理常量（协议 v2 §1）
 */
final class AgentConstants
{
    public const int PROTOCOL_VERSION = 2;
    public const int HEARTBEAT_INTERVAL_MS = 15000;
    public const int CONNECT_TIMEOUT_MS = 10000;
    public const int MAX_FRAME_SIZE = 1048576;

    // 错误码
    public const int AUTH_FAILED = 1001;
    public const int NETWORK_UNREACHABLE = 1002;
    public const int BINLOG_DISABLED = 1003;
    public const int PERMISSION_DENIED = 1004;
    public const int PARSE_ERROR = 1005;
    public const int PROXY_NOT_READY = 1006;
    public const int TIMEOUT = 1007;
    public const int INVALID_PARAM = 1008;
    public const int SERVER_ID_CONFLICT = 1009;
    public const int PROTOCOL_ERROR = 1010;
    public const int BINLOG_POSITION_INVALID = 1011;
    public const int TRANSACTION_COMPRESSED = 1012;
    public const int META_MISSING = 1013;
}