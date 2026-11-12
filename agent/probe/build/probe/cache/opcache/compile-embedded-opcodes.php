<?php
echo PHP_VERSION, "\n";
foreach (array_slice($argv, 1) as $path) {
    if (!opcache_compile_file($path)) {
        fwrite(STDERR, "OPcache could not compile: {$path}\n");
        exit(1);
    }
}