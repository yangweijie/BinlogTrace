<?php
// 端到端探测：agent → connect → binlog-dump → 捕获 binlog-change 帧，确认 primaryKeys 已下发
$base = dirname(__DIR__);
$runtime = $base . '/runtime';
@mkdir($runtime, 0777, true);
$port = random_int(20000, 30000);
$logFile = $runtime . '/pk_probe.log';

function killTree($proc): void {
    $st = proc_get_status($proc);
    if (!$st['running']) return;
    if (DIRECTORY_SEPARATOR === '\\') exec('taskkill /F /T /PID ' . (int) $st['pid'] . ' 2>nul');
    else exec('kill -9 ' . (int) $st['pid'] . ' 2>/dev/null');
}
$proc = proc_open(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/start.php') . ' start --port ' . $port,
    [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']], $pipes, $base, null, ['bypass_shell' => true]
);
if (!is_resource($proc)) { echo "FAIL start\n"; exit(1); }
register_shutdown_function(function () use ($proc): void { killTree($proc); proc_terminate($proc); });

$ready = false;
for ($i = 0; $i < 100; $i++) {
    $e = 0; $s = '';
    $c = @stream_socket_client('tcp://127.0.0.1:' . $port, $e, $s, 0.2);
    if ($c !== false) { fclose($c); $ready = true; break; }
    usleep(100_000);
}
if (!$ready) { echo "FAIL ready\n" . file_get_contents($logFile) . "\n"; exit(1); }
echo "[ok] agent 就绪 {$port}\n";

function wsConnect(int $port) {
    $e = 0; $s = '';
    $t = @stream_socket_client('tcp://127.0.0.1:' . $port, $e, $s, 5);
    if (!$t) return false;
    $key = base64_encode(random_bytes(16));
    fwrite($t, "GET / HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $resp = ''; stream_set_timeout($t, 5);
    while (strpos($resp, "\r\n\r\n") === false && strlen($resp) < 4096) { $chunk = fread($t, 1024); if ($chunk === false || $chunk === '') break; $resp .= $chunk; }
    if (stripos($resp, '101') === false) { fclose($t); return false; }
    return $t;
}
function wsSend($s, string $p): void {
    $len = strlen($p); $mask = random_bytes(4);
    if ($len <= 125) $h = "\x81" . chr(0x80 | $len);
    elseif ($len <= 65535) $h = "\x81" . chr(0x80 | 126) . pack('n', $len);
    else $h = "\x81" . chr(0x80 | 127) . pack('NN', 0, $len);
    $out = ''; for ($i = 0; $i < $len; $i++) $out .= $p[$i] ^ $mask[$i % 4];
    fwrite($s, $h . $mask . $out);
}
function wsRead($s): ?array {
    stream_set_timeout($s, 15);
    $hdr = fread($s, 2); if ($hdr === false || strlen($hdr) < 2) return null;
    $op = ord($hdr[0]) & 0x0F; $m = ord($hdr[1]); $len = $m & 0x7F;
    if ($len === 126) { $ex = fread($s, 2); $len = strlen($ex) >= 2 ? unpack('n', $ex)[1] : 0; }
    elseif ($len === 127) { $ex = fread($s, 8); if (strlen($ex) >= 8) $len = unpack('J', $ex)[1]; }
    $mask = ''; if ($m & 0x80) $mask = fread($s, 4);
    $data = ''; while (strlen($data) < $len) { $c = fread($s, $len - strlen($data)); if ($c === false || $c === '') break; $data .= $c; }
    if ($mask && strlen($mask) >= 4) { $o = ''; for ($i = 0; $i < strlen($data); $i++) $o .= $data[$i] ^ $mask[$i % 4]; $data = $o; }
    return ['opcode' => $op, 'payload' => $data];
}

$s = wsConnect($port);
if (!$s) { echo "FAIL ws\n"; exit(1); }
wsSend($s, json_encode(['v' => 2, 'id' => 'c', 'type' => 'connect', 'ts' => (int)(microtime(true)*1000), 'payload' => ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'password' => 'root', 'database' => 'shengyibao', 'connectTimeoutMs' => 5000, 'serverId' => 0]]));

$connected = false; $dumpFile = ''; $dumpPos = 4;
$deadline = microtime(true) + 15;
$changeCount = 0; $withPk = 0; $samples = [];
while (microtime(true) < $deadline) {
    $f = wsRead($s); if ($f === null) break;
    $d = json_decode($f['payload'], true); if (!is_array($d)) continue;
    $t = $d['type'] ?? '';
    if ($t === 'connected') { $connected = true; $dumpFile = $d['payload']['binlogFile'] ?? ''; $dumpPos = (int)($d['payload']['binlogPos'] ?? 4); echo "[connect] file={$dumpFile} pos={$dumpPos}\n"; }
    elseif ($t === 'binlog-change') {
        $changeCount++; $pks = $d['payload']['primaryKeys'] ?? null;
        if (is_array($pks) && count($pks) > 0) $withPk++;
        if ($d['payload']['kind'] === 'delete' && count($samples) < 4) $samples[] = ['kind' => 'delete', 'schema' => $d['payload']['schema'], 'table' => $d['payload']['table'], 'oldValues' => $d['payload']['before'], 'primaryKeys' => $pks, 'after' => $d['payload']['after']];
    }
    elseif ($t === 'binlog-end') { echo "[binlog-end]\n"; break; }
    elseif ($t === 'error') { echo "[error] " . json_encode($d['payload'], JSON_UNESCAPED_UNICODE) . "\n"; break; }
    if ($connected && $changeCount === 0) {
        // 首次连上后发起 dump（从文件头 pos 4 扫历史，验证 primaryKeys 下发）
        wsSend($s, json_encode(['v' => 2, 'id' => 'd', 'type' => 'binlog-dump', 'ts' => (int)(microtime(true)*1000), 'payload' => ['binlogFile' => $dumpFile ?: 'binlog.000042', 'binlogPos' => 4, 'slaveFlags' => 0]]));
        $connected = false; // 只发一次
    }
}
echo "捕获 {$changeCount} 个 change，其中含 primaryKeys 的 {$withPk} 个\n";
foreach ($samples as $x) echo json_encode($x, JSON_UNESCAPED_UNICODE) . PHP_EOL;
fclose($s);
killTree($proc); proc_terminate($proc);
echo "--- agent log tail ---\n" . substr(trim((string)file_get_contents($logFile)), -600) . "\n";
