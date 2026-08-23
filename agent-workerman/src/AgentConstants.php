<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * AgentConstants — 协议 v2 常量与错误码
 * 与 agent/src/Protocol/Frame.php（TypePHP 版）保持一致，前端无需改动
 */
final class AgentConstants
{
    public const PROTOCOL_VERSION = 2;
    public const HEARTBEAT_INTERVAL_MS = 15000;
    public const CONNECT_TIMEOUT_MS = 10000;
    public const MAX_FRAME_SIZE = 1048576;

    // 错误码
    public const AUTH_FAILED = 1001;
    public const NETWORK_UNREACHABLE = 1002;
    public const BINLOG_DISABLED = 1003;
    public const PERMISSION_DENIED = 1004;
    public const PARSE_ERROR = 1005;
    public const PROXY_NOT_READY = 1006;
    public const TIMEOUT = 1007;
    public const INVALID_PARAM = 1008;
    public const SERVER_ID_CONFLICT = 1009;
    public const PROTOCOL_ERROR = 1010;
    public const BINLOG_POSITION_INVALID = 1011;
    public const TRANSACTION_COMPRESSED = 1012;
    public const META_MISSING = 1013;
    public const INTERNAL_ERROR = 1099;
}
