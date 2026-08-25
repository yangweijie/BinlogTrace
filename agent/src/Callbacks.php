<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * 编译期回调抽象（替代 TypePHP 不支持的闭包）。
 * AgentHandler 依赖这些接口而非具体类，避免与 Server 形成编译期循环依赖。
 */

interface Responder
{
    public function respond(string $body, array $headers, int $code): void;
}

interface JsonResponder
{
    public function respondJson(string $json): void;
}

interface SseWriter
{
    public function write(string $sseLine): void;
}

interface SseSetup
{
    public function setup(AgentHandler $handler): void;
}
