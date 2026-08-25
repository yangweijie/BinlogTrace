<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * Server — 极简原生 HTTP 服务器（不依赖 workerman / thinkphp）
 *
 * 实现：
 *   - stream_socket_server 监听 TCP
 *   - stream_select 事件循环，并发处理多连接
 *   - 支持 SSE 长流（dump）：HTTP 头 text/event-stream 后保持连接；dump 由 C++ 后台
 *     线程抓取并解析（mysqlbox_dump_*），Server 在事件循环 tick 中按节奏调用
 *     handler->pollDump() 拉取已解析行变更并转发 binlog-change 帧；心跳由 select 超时驱动
 *
 * 跨平台：Windows 下 php -S 有 bug 卡死，但原生 socket + stream_select 无此问题。
 */
final class Server
{
    private $server;
    /** @var array<int, ClientConn> */
    private array $clients = [];
    /** @var array<int, AgentHandler> dumping handler 映射（owner handler -> client id 同键） */
    private array $dumpHandlers = [];
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
        // 仅保留合法流资源：若某个 fd（如已退出的 dump 子进程管道）残留为失效资源，
        // 过滤掉，避免 stream_select 对失效资源抛错使整个服务崩溃。
        $filtered = [];
        foreach ($read as $r) {
            if (is_resource($r)) {
                $filtered[] = $r;
            }
        }
        $read = $filtered;

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
                $this->clients[$id] = new ClientConn($conn, $peer);
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

        // 3) dump：遍历正在 dump 的 handler，从 C++ 队列拉取已解析事件并转发 SSE
        foreach ($this->dumpHandlers as $hid => $handler) {
            if (!$handler instanceof AgentHandler) {
                continue;
            }
            $ended = $handler->pollDump();
            if ($ended) {
                // dump 结束（正常完成或出错）：关闭对应 SSE 客户端连接
                unset($this->dumpHandlers[$hid]);
                foreach ($this->clients as $cid => $cc) {
                    if ($cc->owner === $handler && $cc->keepAlive()) {
                        $cc->close();
                        unset($this->clients[$cid]);
                    }
                }
            }
        }

        // 4) 心跳：仅对正在 dump 的连接推送
        $now = microtime(true);
        if ($now - $this->lastHeartbeat >= $this->heartbeatSec) {
            $this->lastHeartbeat = $now;
            foreach ($this->dumpHandlers as $handler) {
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
        $router->handle($req, new ConnResponder($c), new ConnSseSetup($c, $this));
    }

    public function registerDumpHandler(AgentHandler $handler): void
    {
        $this->dumpHandlers[spl_object_id($handler)] = $handler;
    }
}

final class ConnResponder implements Responder
{
    public function __construct(private ClientConn $c)
    {
    }

    public function respond(string $body, array $headers, int $code): void
    {
        $this->c->respond($body, $headers, $code);
    }
}

final class SseLineWriter implements SseWriter
{
    public function __construct(private ClientConn $c)
    {
    }

    public function write(string $sseLine): void
    {
        $this->c->writeSse($sseLine);
    }
}

final class ConnSseSetup implements SseSetup
{
    public function __construct(
        private ClientConn $c,
        private Server $server
    ) {
    }

    public function setup(AgentHandler $handler): void
    {
        $this->c->beginSse();
        $handler->setSseWriter(new SseLineWriter($this->c));
        $this->c->owner = $handler;
        // SSE 连接专用于 dump：登记到 Server 的 dumpHandlers，由事件循环 tick 调 pollDump 拉取事件
        $this->server->registerDumpHandler($handler);
    }
}
