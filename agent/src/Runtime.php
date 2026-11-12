<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * Runtime — 进程级路径与运行形态（bin 模式下唯一的运行期事实来源）
 *
 * 为什么不用 PHP_BINARY / __DIR__（tpc 实测，见 findings.md）：
 *   - PHP_BINARY 在编译二进制中指向 tpc.exe（编译器），不是本进程可执行文件
 *   - __DIR__ 是编译期冻结的源码路径，部署机上不存在
 * 可靠事实：$argv[0] = 本二进制的真实路径（embed SAPI 实测）。
 */
final class Runtime
{
    /** @var string 二进制绝对路径 */
    public static string $binPath = '';
    /** @var string 二进制所在目录（.env / dist / vendor / bin 的定位基准） */
    public static string $binDir = '';
    /** @var bool 是否运行在编译后的 embed SAPI 中（false = php 解释器开发模式） */
    public static bool $isEmbed = false;

    public static function init(string $argv0): void
    {
        self::$isEmbed = PHP_SAPI === 'embed';
        $real = realpath($argv0 !== '' ? $argv0 : '.');
        self::$binPath = $real !== false ? $real : $argv0;
        $dir = dirname(self::$binPath);
        self::$binDir = $dir !== '' ? $dir : getcwd();
    }
}
