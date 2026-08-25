<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * Server — 极简原生 HTTP 服务器（不依赖 workerman / thinkphp）
 *
 * 实现：
 *   - stream_socket_server 监听 TCP
 *   - stream_select 事件循环，并发处理多连接 + dump 子进程 stdout 管道
 *   - 支持 SSE 长流（dump）：HTTP 头 text/event-stream 后保持连接，子进程
 *     可读时逐行转发 binlog-change 帧；心跳由 select 超时驱动
 *
 * 跨平台：Windows 下 php -S 有 bug 卡死，但原生 socket + stream_select 无此问题。
 */
final class Server
{
    private $server;
    /** @var array<int, ClientConn> */
    private array $clients = [];
    /** @var array<int, AgentHandler> dump 子进程 stdout 管道 -> handler 映射 */
    private array $dumpPipes = [];
    /** @var array<int, resource> fd -> 管道资源（select 用） */
    private array $pipeFds = [];
    /** @var array<int, resource> fd -> stderr 管道资源（select + 错误日志用） */
    private array $errFds = [];
    /** @var array<int, int> stdout fdKey -> 对应 stderr fdKey（dump 结束时一并清理） */
    private array $errKeys = [];
    private int $heartbeatSec = 15;
    private float $lastHeartbeat = 0;

    public function __construct(
        private string $host,
        private int $port
    ) {
    }

    public function run(): void
    {
        $url = "tcp://{$this->host}:{$this->port}";
        $this->server = @stream_socket_server(
            $url,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if ($this->server === false) {
            fwrite(STDERR, "agent: 无法监听 {$url}：{$errstr} ({$errno})\n");
            exit(1);
        }
        stream_set_blocking($this->server, false);
        fwrite(STDOUT, "agent: HTTP 服务已启动于 http://{$this->host}:{$this->port}\n");
        fwrite(STDOUT, "agent: 路由 /connect /dump /query /close\n");
        fwrite(STDOUT, "agent: Ctrl+C 停止\n");

        $this->lastHeartbeat = microtime(true);

        while (true) {
            $this->tick();
        }
    }

    private function tick(): void
    {
        $read = [$this->server];
        foreach ($this->clients as $c) {
            if (!$c->closed()) {
                $read[] = $c->stream();
            }
        }
        foreach ($this->pipeFds as $fd) {
            $read[] = $fd;
        }
        foreach ($this->errFds as $fd) {
            $read[] = $fd;
        }
        // 仅保留合法流资源：若某个 fd（如已退出的 dump 子进程管道）残留为失效资源，
        // 过滤掉，避免 stream_select 对失效资源抛错使整个服务崩溃。
        $read = $this->filterReadable($read);

        $write = [];
        $except = [];
        $timeoutSec = 0.2; // 心跳精度
        $n = @stream_select($read, $write, $except, (int) $timeoutSec, (int) ($timeoutSec * 1_000_000));
        if ($n === false) {
            // 被信号打断等，继续
            return;
        }

        // 1) 新连接
        if (in_array($this->server, $read, true)) {
            $conn = @stream_socket_accept($this->server, 0, $peer);
            if (is_resource($conn)) {
                stream_set_blocking($conn, false);
                $id = (int) $conn;
                $this->clients[$id] = new ClientConn($conn, $peer, $this);
            }
        }

        // 2) 客户端可读（请求数据到达 / 连接关闭）
        foreach ($this->clients as $id => $c) {
            if ($c->closed()) {
                unset($this->clients[$id]);
                continue;
            }
            if (!$c->dispatched() && in_array($c->stream(), $read, true)) {
                $c->feed();
                if ($c->hasFullRequest()) {
                    $c->markDispatched();
                    $this->dispatch($c);
                    if (!$c->keepAlive()) {
                        // 短响应：写完后关闭（SSE 连接会 keepAlive 保持）
                        $c->close();
                        unset($this->clients[$id]);
                    }
                } elseif ($c->eof()) {
                    $c->close();
                    unset($this->clients[$id]);
                }
            }
        }

        // 3a) dump 子进程 stderr 可读（错误诊断）
        foreach ($this->errFds as $efd => $epipe) {
            if (in_array($epipe, $read, true)) {
                $chunk = @fread($epipe, 8192);
                if ($chunk !== '' && $chunk !== false) {
                    error_log('agent[dump-err]: ' . trim($chunk));
                }
            }
        }

        // 3b) dump 子进程 stdout 可读
        foreach ($this->pipeFds as $fdKey => $pipe) {
            if (in_array($pipe, $read, true)) {
                $handler = $this->dumpPipes[$fdKey] ?? null;
                if ($handler instanceof AgentHandler) {
                    $status = $handler->onDumpReadable();
                    if ($status === 'ended') {
                        // dump 结束：关闭对应 SSE 客户端连接
                        unset($this->dumpPipes[$fdKey], $this->pipeFds[$fdKey]);
                        // 同时清理 stderr 管道（finishDump 已 fclose，否则会残留在 errFds 中导致 stream_select 崩溃）
                        $errKey = $this->errKeys[$fdKey] ?? null;
                        if ($errKey !== null) {
                            unset($this->errFds[$errKey], $this->errKeys[$fdKey]);
                        }
                        foreach ($this->clients as $cid => $cc) {
                            if ($cc->owner === $handler && $cc->keepAlive()) {
                                $cc->close();
                                unset($this->clients[$cid]);
                            }
                        }
                    }
                }
            }
        }

        // 4) 心跳：仅对正在 dump 的连接推送
        $now = microtime(true);
        if ($now - $this->lastHeartbeat >= $this->heartbeatSec) {
            $this->lastHeartbeat = $now;
            foreach ($this->dumpPipes as $handler) {
                if ($handler instanceof AgentHandler) {
                    $handler->sendHeartbeat();
                }
            }
        }
    }

    private function dispatch(ClientConn $c): void
    {
        $req = $c->request();
        $router = new Router();
        $router->handle($req, $c);
    }

    /**
     * 由 ClientConn::registerDumpPipe 转发调用：把 dump 子进程管道注册到事件循环。
     */
    public function registerDumpPipe($pipe, ?AgentHandler $handler): void
    {
        if (!is_resource($pipe) || $handler === null) {
            return;
        }
        $fdKey = (int) $pipe;
        $this->pipeFds[$fdKey] = $pipe;
        $this->dumpPipes[$fdKey] = $handler;
        $err = $handler->dumpErrPipe();
        if (is_resource($err)) {
            $errKey = (int) $err;
            $this->errFds[$errKey] = $err;
            $this->errKeys[$fdKey] = $errKey;
        }
    }

    private function filterReadable(array $read): array
    {
        $out = [];
        foreach ($read as $r) {
            if (is_resource($r)) {
                $out[] = $r;
            }
        }
        return array_values($out);
    }
}
