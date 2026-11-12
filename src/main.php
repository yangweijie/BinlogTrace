<?php

declare(strict_types=1);

/**
 * TypePHP 二进制入口（mode: bin）。
 *
 * 功能：
 *   - serve：启动进程内 HTTP 服务（API + 前端静态文件），关闭进程即服务退出
 *   - 端口优先级：--port 命令行 > 二进制同级 .env 的 PORT > 8080
 *   - __dump_worker：隐藏子命令——dump 子进程自我复用（单二进制，无需外部 PHP）
 *
 * 编译：tpc-build.bat . project.yml --no-progress --build-dir build\agent
 * 运行：binlog-agent.exe serve
 */

use DmsAgent\DumpWorker;
use DmsAgent\EnvLoader;
use DmsAgent\Runtime;
use DmsAgent\Server;

function main(int $argc, array $argv): void
{
    Runtime::init($argv[0] ?? '');
    // .env 与二进制同级（用户目标：通过二进制同级 .env 指定端口）
    EnvLoader::load(Runtime::$binDir . '/.env');
    // 开发模式（php bin/agent）下补充 cwd 的 .env
    if (!Runtime::$isEmbed) {
        $cwdEnv = getcwd() . '/.env';
        if (is_file($cwdEnv) && Runtime::$binDir . '/.env' !== $cwdEnv) {
            EnvLoader::load($cwdEnv);
        }
    }

    $args = array_slice($argv, 1);
    $cmd = $args[0] ?? 'serve';

    // 隐藏子命令：dump 子进程（由 handleDump 以自身路径再次拉起）
    if ($cmd === '__dump_worker') {
        DumpWorker::run(array_slice($args, 1));
        return;
    }

    if ($cmd === 'help' || $cmd === '--help' || $cmd === '-h') {
        echo "DMS Agent — 轻量 MySQL binlog 追踪代理（原生 HTTP 服务，自带前端）\n\n";
        echo "用法:\n";
        echo "  binlog-agent serve [--host <ip>] [--port <port>]   启动 HTTP 服务\n";
        echo "  binlog-agent help                                 显示本帮助\n\n";
        echo "配置（二进制同级 .env，示例见 .env.example）:\n";
        echo "  PORT=8080        监听端口（前端静态文件与 API 同端口）\n";
        echo "  HOST=127.0.0.1   监听地址（默认 127.0.0.1）\n\n";
        echo "路由:\n";
        echo "  GET  /           前端 SPA（dist/ 静态文件）\n";
        echo "  GET  /ping       健康检查\n";
        echo "  POST /connect    测试连接 + 采集元数据\n";
        echo "  POST /query      session 只读查询\n";
        echo "  POST /dump       session 启动 binlog 解析（SSE）\n";
        echo "  POST /close      销毁会话\n";
        return;
    }

    if ($cmd !== 'serve') {
        fwrite(STDERR, "未知命令: {$cmd}\n");
        exit(1);
    }

    // 端口/地址：命令行 > .env > 默认
    $host = EnvLoader::get('HOST', '127.0.0.1');
    $port = EnvLoader::getInt('PORT', 8080);
    $portFrom = EnvLoader::has('PORT') ? '（端口来自 .env）' : '（默认端口，可经 .env 的 PORT 指定）';
    for ($i = 1; $i < count($args); $i++) {
        if ($args[$i] === '--host' && isset($args[$i + 1])) {
            $host = $args[++$i];
        } elseif ($args[$i] === '--port' && isset($args[$i + 1])) {
            $port = (int) $args[++$i];
            $portFrom = '（端口来自命令行 --port）';
        }
    }

    echo "agent: binDir = " . Runtime::$binDir . "\n";
    echo "agent: 监听 {$host}:{$port}{$portFrom}\n";

    $server = new Server($host, $port);
    $server->run();
}
