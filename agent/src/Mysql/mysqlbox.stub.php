<?php
// mysqlbox.stub.php — C++ 直连 MySQL 层函数声明（实现见 mysqlbox.cc）
// 这些函数由 aot-compiler 的混合 C++ 机制绑定，PHP 侧如同调用普通函数。
// 返回/接收的 MySQLBox 是 C++ Box 资源，PHP 侧用 mixed 表示。
// 注意：stub 必须是空函数体 {} 且需声明返回类型（对齐 examples/prime 范式）。

function mysqlbox_connect(string $host, int $port, string $user, string $pass, string $db, int $timeoutSec): mixed
{

}

function mysqlbox_query(mixed $box, string $sql): array
{

}

function mysqlbox_server_version(mixed $box): string
{

}

function mysqlbox_close(mixed $box): void
{

}

function mysqlbox_dump_start(string $host, int $port, string $user, string $pass, string $file, int $pos, int $serverId): mixed
{

}

function mysqlbox_dump_poll(mixed $session): array
{

}

function mysqlbox_dump_stop(mixed $session): void
{

}
