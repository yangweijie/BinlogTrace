<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * DumpWorker — dump 子进程入口（编译进二进制的薄壳）
 *
 * 设计：binlog 解析业务逻辑（krowinski 库）以普通 PHP 随包分发（vendor/ + bin/dump_worker.php），
 * 由本二进制再次拉起自己（__dump_worker 子命令）后经 embed SAPI 的 ZendVM 执行磁盘脚本。
 * 运行期 require 普通 PHP 不受 TypePHP 编译器限制（探针实测），krowinski 的
 * 匿名类/闭包/动态特性全部按原生 PHP 语义工作。
 *
 * 为什么不把 krowinski 编译进来：继承链须全静态编译（见 skill），vendor 全量编译
 * 风险高；embedded-files 在当前 tpc 发行版不可用（缺 thirdparty 源文件）。
 */
final class DumpWorker
{
    public static function run(array $args): void
    {
        $autoload = Runtime::$binDir . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        } else {
            fwrite(STDERR, "dump_worker: 缺少 {$autoload}（打包需包含 vendor/）\n");
        }
        $worker = Runtime::$binDir . '/bin/dump_worker.php';
        if (!is_file($worker)) {
            fwrite(STDERR, "dump_worker: 缺少 {$worker}（打包需包含 bin/dump_worker.php）\n");
            exit(2);
        }
        require $worker;
        \dms_dump_worker_main($args);
    }
}
