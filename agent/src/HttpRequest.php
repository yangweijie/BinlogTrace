<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * HttpRequest — 解析后的 HTTP 请求（仅含路由所需字段）
 */
final class HttpRequest
{
    public function __construct(
        public string $method,
        public string $uri,      // 含 query string，如 /dump?x=1
        public string $body
    ) {
    }

    public function path(): string
    {
        $q = strpos($this->uri, '?');
        if ($q !== false) {
            return substr($this->uri, 0, $q);
        }
        return $this->uri;
    }

    /** @return array<mixed> */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
