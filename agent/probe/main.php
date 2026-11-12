<?php

declare(strict_types=1);

function tryRequire(string $p): void
{
    try {
        require $p;
        echo "REQUIRE OK: {$p}\n";
    } catch (\Throwable $e) {
        echo "REQUIRE FAIL: {$p} => " . $e->getMessage() . "\n";
    }
}

function tryRead(string $p): void
{
    $is = is_file($p);
    $c = $is ? file_get_contents($p) : false;
    echo "READ {$p}: is_file=" . ($is ? 'Y' : 'N') . " len=" . ($c === false ? -1 : strlen($c)) . "\n";
}

function main(int $argc, array $argv): void
{
    echo "=== embed probe ===\n";
    echo "argv0=" . ($argv[0] ?? '') . "\n";
    $binDir = dirname(realpath($argv[0] ?? '.'));
    echo "binDir={$binDir}\n";
    tryRequire($binDir . '/ondisk/plain.php');
    echo "class ProbeDisk\\Plain = " . (class_exists('ProbeDisk\Plain') ? 'LOADED' : 'MISSING') . "\n";
    echo "ext bcmath = " . (extension_loaded('bcmath') ? 'BUILT-IN' : 'missing') . "\n";
    echo "ext sockets = " . (extension_loaded('sockets') ? 'BUILT-IN' : 'missing') . "\n";
    echo "bcmath calc = " . bcadd('12345678901234567890', '1') . "\n";
    if (class_exists('ProbeDisk\Plain')) {
        echo "call: " . \ProbeDisk\Plain::hi() . "\n";
    }
    tryRead('payload/data.txt');
    tryRead(__DIR__ . '/payload/data.txt');
    echo "=== done ===\n";
}
