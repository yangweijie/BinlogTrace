<?php
declare(strict_types=1);

function main(int $argc, array $argv): void
{
    $port = 8080;
    $host = '127.0.0.1';
    $dbPort = 3306;
    $user = 'jaylab';
    $password = 'o3kBmkhX03ItVVuQ';
    $database = 'jay_music';
    $binlogFile = 'mysql-bin.000002';
    $binlogPos = 4;

    for ($i = 1; $i < $argc; $i++) {
        if ($argv[$i] === '--port' && $i + 1 < $argc) {
            $port = (int)$argv[$i + 1];
            $i++;
        }
    }

    $client = new Client();
    if ($client->connect($host, $dbPort, $user, $password, $database, 10)) {
        error_log('Agent (TypePHP bin) connected to MySQL ' . $host . ':' . $dbPort . ' db=' . $database);
    } else {
        error_log('Agent (TypePHP bin) MySQL connect failed');
        return;
    }

    if ($client->binlogDump($binlogFile, $binlogPos, 1, 0)) {
        error_log('Agent (TypePHP bin) binlog dump started: file=' . $binlogFile . ' pos=' . $binlogPos);
    }

    while (true) {
        $event = $client->readEvent();
        if ($event !== false && $event !== null) {
            error_log('Agent (TypePHP bin) binlog event: type=' . ($event['eventType'] ?? 0) . ' ts=' . ($event['timestamp'] ?? 0) . ' file=' . $binlogFile);
        }
        $rows = [];
        $metaResult = $client->query('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA');
        if ($metaResult !== false) {
            $rows = $metaResult['rows'] ?? [];
            error_log('Agent (TypePHP bin) meta query returned ' . count($rows) . ' schemas');
        }
        break;
    }

    $client->close();
    error_log('Agent (TypePHP bin) stopped');
}
