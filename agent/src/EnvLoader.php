<?php

declare(strict_types=1);

namespace DmsAgent;

/**
 * EnvLoader — 极简 .env 解析（无第三方依赖，二进制自带）
 *
 * 语法：KEY=VALUE（行内 # 注释、首尾空白、成对引号剥除）；空行与 # 开头行跳过。
 * 加载后同时写入 putenv（子进程 dump worker 可继承）与 $_ENV。
 */
final class EnvLoader
{
    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $envFile): void
    {
        if (!is_file($envFile)) {
            return;
        }
        $content = file_get_contents($envFile);
        if ($content === false || $content === '') {
            return;
        }
        $lines = preg_split("/\r\n|\n|\r/", $content);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            $eq = strpos($trimmed, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $eq));
            $val = trim(substr($trimmed, $eq + 1));
            if ($key === '') {
                continue;
            }
            // 行内注释（未加引号时）：VALUE  # comment
            if ($val !== '' && ($val[0] !== '"' && $val[0] !== "'")) {
                $hash = strpos($val, ' #');
                if ($hash !== false) {
                    $val = trim(substr($val, 0, $hash));
                }
            }
            // 成对引号剥除
            $len = strlen($val);
            if ($len >= 2) {
                $first = $val[0];
                $last = $val[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $val = substr($val, 1, $len - 2);
                }
            }
            self::$values[$key] = $val;
            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
        }
    }

    /** 键是否在本进程 .env 中显式提供（区别于进程环境变量回退） */
    public static function has(string $key): bool
    {
        return isset(self::$values[$key]);
    }

    /** 读取 .env 值（未加载的键回退进程环境变量），都无则给默认 */
    public static function get(string $key, string $default = ''): string
    {
        $v = self::$values[$key] ?? getenv($key);
        if ($v === false || $v === '') {
            return $default;
        }
        return (string) $v;
    }

    public static function getInt(string $key, int $default): int
    {
        $v = self::get($key, '');
        if ($v === '' || !preg_match('/^\d+$/', $v)) {
            return $default;
        }
        return (int) $v;
    }
}
