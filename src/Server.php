<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * Server — 极简原生 HTTP 服务器（不依赖 workerman / thinkphp）
 *
 * 实现：
 *   - stream_socket_server 监听 TCP
 *   - stream_select 事件循环处理多连接
 *   - SSE 长流（dump）：HTTP 头 text/event-stream 后保持连接，
 *     子进程输出走 runtime/dump_*.out 文件增量轮询（见 AgentHandler::dumpOutFile），
 *     事件循环里绝不 fread proc_open 管道 —— Windows 上那样做会阻塞十几秒
 *
 * 跨平台：Windows 下 php -S 有 bug 卡死，但原生 socket + stream_select 无此问题。
 */
final class Server
{
    private $server;
    /** @var array<int, ClientConn> */
    private array $clients = [];
    private int $heartbeatSec = 15;
    private float $lastHeartbeat = 0;
    /** 会话空闲扫描间隔（秒）与上次扫描时间 */
    private const SWEEP_INTERVAL_SEC = 30.0;
    /** 单连接出站积压上限：超过即判定客户端已不再读取 */
    private const MAX_PENDING_BYTES = 16777216;
    private float $lastSweep = 0;
    /** 进程启动时间戳（/ping 的 uptime 用；bin 模式下 $_SERVER 不可靠） */
    private static float $startedAt = 0;

    public static function uptimeSec(): int
    {
        return self::$startedAt > 0 ? (int) (microtime(true) - self::$startedAt) : 0;
    }

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
        $this->lastSweep = $this->lastHeartbeat;
        self::$startedAt = $this->lastHeartbeat;

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
        $read = $this->filterReadable($read);

        $write = [];
        $except = [];
        $timeoutSec = 0.2; // 心跳与 dump 轮询精度
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
        foreach ($this->clients as $cid => $c) {
            if ($c->closed()) {
                $this->releaseClient($c);
                unset($this->clients[$cid]);
                continue;
            }
            // 先补写上一轮没写完的出站缓冲，再判断能否关闭
            if ($c->pendingOutput()) {
                $c->flushPending();
            }
            // 客户端长时间不读（页面卡死/代理断链）：缓冲会无上限堆积，
            // 超阈值直接判定为死连接，停 dump 并断开，避免拖垮整个服务
            if ($c->pendingBytes() > self::MAX_PENDING_BYTES) {
                error_log('agent: 客户端写入积压超阈值，判定为死连接');
                $this->releaseClient($c);
                $c->close();
                unset($this->clients[$cid]);
                continue;
            }
            if ($c->shouldCloseAfterFlush() && !$c->pendingOutput()) {
                $this->releaseClient($c);
                $c->close();
                unset($this->clients[$cid]);
                continue;
            }
            if (!in_array($c->stream(), $read, true)) {
                continue;
            }
            if (!$c->dispatched()) {
                $c->feed();
                if ($c->hasFullRequest()) {
                    $c->markDispatched();
                    $this->dispatch($c);
                    if (!$c->keepAlive()) {
                        // 短响应：缓冲冲完才关（大结果集一次 fwrite 可能只写出一部分）
                        $c->flushPending();
                        if (!$c->pendingOutput()) {
                            $this->releaseClient($c);
                            $c->close();
                            unset($this->clients[$cid]);
                        }
                    }
                } elseif ($c->eof()) {
                    $this->releaseClient($c);
                    $c->close();
                    unset($this->clients[$cid]);
                }
            } elseif ($c->keepAlive()) {
                // SSE 连接可读 = 客户端断开（select 已判可读，读出空即 EOF）
                if ($c->detectDead()) {
                    $this->releaseClient($c);
                    $c->close();
                    unset($this->clients[$cid]);
                }
            }
        }

        // 3) dump 子进程：轮询输出文件（非阻塞）+ 回收已退出的进程句柄
        AgentHandler::pollActiveDumps();
        AgentHandler::reapFinished();

        // 4) 心跳：仅对正在 dump 的连接推送
        $now = microtime(true);
        if ($now - $this->lastHeartbeat >= $this->heartbeatSec) {
            $this->lastHeartbeat = $now;
            foreach (AgentHandler::activeDumpHandlers() as $handler) {
                $handler->sendHeartbeat();
            }
        }

        // 5) 会话空闲回收：HTTP 模式没有连接关闭事件，未 close 的会话会一直占着
        //    handler + MySQL 连接（页面刷新/关标签是常态），按 TTL 主动销毁
        if ($now - $this->lastSweep >= self::SWEEP_INTERVAL_SEC) {
            $this->lastSweep = $now;
            $dropped = SessionManager::sweepIdle(SessionManager::ttlSec());
            if ($dropped > 0) {
                error_log('agent: 回收空闲会话 ' . $dropped . ' 个（TTL=' . SessionManager::ttlSec() . 's）');
            }
        }
    }

    private function dispatch(ClientConn $c): void
    {
        $req = $c->request();
        $router = new Router();
        $router->handle($req, $c);
    }

    /** 客户端连接被释放：若它正在 dump，停子进程（会话本身保留） */
    private function releaseClient(ClientConn $c): void
    {
        $owner = $c->owner;
        if ($owner === null) {
            return;
        }
        $owner->stopDump();
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
