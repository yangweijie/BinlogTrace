<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * ClientConn — 单个 HTTP 客户端连接的读写封装
 * 负责：累积请求字节、解析 HTTP 头与 body、写出响应、SSE 持续写 data 行、保持/关闭连接。
 */
final class ClientConn
{
    private string $buf = '';
    private bool $reqParsed = false;
    private bool $eof = false;
    private bool $sse = false;
    private bool $closed = false;

    private ?HttpRequest $request = null;
    /** 是否已派发过（SSE/短连接只派发一次，避免事件循环重复 dispatch） */
    private bool $dispatched = false;
    /** dump SSE 关联的业务 handler（用于 dump 结束后关闭连接） */
    public ?AgentHandler $owner = null;
    /** 标准 CORS 响应头（短响应默认带上，避免浏览器拦截跨域） */
    private array $cors;
    /** 所属 Server（用于把 dump 管道注册到事件循环） */
    private ?Server $server = null;

    public function markDispatched(): void
    {
        $this->dispatched = true;
    }

    public function dispatched(): bool
    {
        return $this->dispatched;
    }

    public function __construct(
        private $stream,
        private string $peer,
        ?Server $server = null
    ) {
        $this->server = $server;
        $this->cors = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
        ];
    }

    public function corsHeaders(): array
    {
        return $this->cors;
    }

    public function stream()
    {
        return $this->stream;
    }

    public function closed(): bool
    {
        return $this->closed;
    }

    public function eof(): bool
    {
        return $this->eof;
    }

    public function keepAlive(): bool
    {
        return $this->sse;
    }

    /** 读取客户端可写数据 */
    public function feed(): void
    {
        if ($this->sse) {
            return; // SSE 期间不再读请求
        }
        $chunk = @fread($this->stream, 65536);
        if ($chunk === '' || $chunk === false) {
            if (feof($this->stream)) {
                $this->eof = true;
            }
            return;
        }
        $this->buf .= $chunk;
    }

    public function hasFullRequest(): bool
    {
        if ($this->reqParsed) {
            return true;
        }
        $headerEnd = strpos($this->buf, "\r\n\r\n");
        if ($headerEnd === false) {
            $headerEnd = strpos($this->buf, "\n\n");
        }
        if ($headerEnd === false) {
            return false;
        }
        $headerPart = substr($this->buf, 0, $headerEnd);
        $lines = preg_split("/\r\n|\n/", $headerPart);
        if ($lines === false || count($lines) === 0) {
            return false;
        }
        $reqLine = explode(' ', trim($lines[0]));
        if (count($reqLine) < 2) {
            return false;
        }
        $method = $reqLine[0];
        $uri = $reqLine[1];
        $contentLength = 0;
        foreach (array_slice($lines, 1) as $ln) {
            if (preg_match('/^content-length\s*:\s*(\d+)/i', $ln, $m)) {
                $contentLength = (int) $m[1];
            }
        }
        $bodyStart = $headerEnd + ($headerPart !== $this->buf ? 4 : 2);
        $receivedBody = strlen(substr($this->buf, $bodyStart));
        if ($receivedBody < $contentLength) {
            return false;
        }
        $body = $contentLength > 0 ? substr($this->buf, $bodyStart, $contentLength) : '';
        $this->request = new HttpRequest($method, $uri, $body);
        $this->reqParsed = true;
        return true;
    }

    public function request(): HttpRequest
    {
        return $this->request;
    }

    /** 短响应（connect/query/close）。headers 缺省为 CORS 头。 */
    public function respond(string $body, ?array $headers = null, int $code = 200): void
    {
        if ($this->sse) {
            return;
        }
        $headers = $headers ?? $this->cors;
        $map = [
            200 => '200 OK',
            204 => '204 No Content',
            400 => '400 Bad Request',
            404 => '404 Not Found',
            405 => '405 Method Not Allowed',
        ];
        $status = $map[$code] ?? '500 Internal Server Error';
        $out = "HTTP/1.1 {$status}\r\n";
        $out .= "Connection: close\r\n";
        $out .= "Content-Type: application/json; charset=utf-8\r\n";
        $out .= "Content-Length: " . strlen($body) . "\r\n";
        foreach ($headers as $k => $v) {
            $out .= "{$k}: {$v}\r\n";
        }
        $out .= "\r\n" . $body;
        @fwrite($this->stream, $out);
    }

    /** 进入 SSE 模式：先写响应头，后续 writeSse 持续写 data 行 */
    public function beginSse(): void
    {
        $this->sse = true;
        $out = "HTTP/1.1 200 OK\r\n";
        // 跨域（前端与 agent 不同源，SSE 响应也需带 CORS，否则浏览器拦截）
        $out .= "Access-Control-Allow-Origin: *\r\n";
        $out .= "Access-Control-Allow-Methods: POST, OPTIONS\r\n";
        $out .= "Access-Control-Allow-Headers: Content-Type\r\n";
        $out .= "Content-Type: text/event-stream; charset=utf-8\r\n";
        $out .= "Cache-Control: no-cache\r\n";
        $out .= "Connection: keep-alive\r\n";
        $out .= "X-Accel-Buffering: no\r\n";
        $out .= "\r\n";
        @fwrite($this->stream, $out);
        // 先发一个注释保活（部分代理需要）
        @fwrite($this->stream, ": connected\n\n");
    }

    public function writeSse(string $sseLine): void
    {
        if (!$this->sse) {
            return;
        }
        @fwrite($this->stream, $sseLine);
    }

    /** 把 dump 子进程 stdout 管道注册到 Server 事件循环（由 AgentHandler 调用） */
    public function registerDumpPipe($pipe): void
    {
        if ($this->server !== null) {
            $this->server->registerDumpPipe($pipe, $this->owner);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
    }
}
