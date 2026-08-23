<?php
// 复现 F 用例：connect → dump（当前 pos）→ query，打印所有帧
$port = random_int(20000, 30000);
$proc = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/start.php') . ' start --port ' . $port,
    [1 => ['file', dirname(__DIR__) . '/runtime/repro_f.log', 'w'], 2 => ['file', dirname(__DIR__) . '/runtime/repro_f.log', 'a']],
    $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
register_shutdown_function(function () use ($proc): void {
    $st = proc_get_status($proc);
    if ($st['running']) { exec('taskkill /F /T /PID ' . (int) $st['pid'] . ' 2>nul'); }
});
$ready = false;
for ($i = 0; $i < 100; $i++) {
    $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $e, $es, 0.2);
    if ($s !== false) { fclose($s); $ready = true; break; }
    usleep(100000);
}
if (!$ready) { echo "FAIL agent 未就绪\n"; exit(1); }
function wsC(int $port) {
    $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $e, $es, 5);
    $key = base64_encode(random_bytes(16));
    fwrite($s, "GET / HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $resp = '';
    while (strpos($resp, "\r\n\r\n") === false && strlen($resp) < 4096) { $resp .= fread($s, 1024); }
    return $s;
}
function wsS($s, string $p): void {
    $len = strlen($p); $mask = random_bytes(4);
    $h = $len <= 125 ? "\x81" . chr(0x80 | $len) : "\x81" . chr(0x80 | 126) . pack('n', $len);
    $m = ''; for ($i = 0; $i < $len; $i++) { $m .= $p[$i] ^ $mask[$i % 4]; }
    fwrite($s, $h . $mask . $m);
}
function wsR($s, float $t = 2): ?array {
    stream_set_timeout($s, (int) $t, (int) (($t - (int) $t) * 1e6));
    $hdr = fread($s, 2);
    if ($hdr === false || strlen($hdr) < 2) return null;
    $second = ord($hdr[1]); $len = $second & 0x7F;
    if ($len === 126) { $ext = fread($s, 2); $len = strlen($ext) >= 2 ? unpack('n', $ext)[1] : 0; }
    $mask = '';
    if (($second & 0x80) !== 0) { $mask = fread($s, 4); }
    $data = '';
    while (strlen($data) < $len) { $c = fread($s, $len - strlen($data)); if ($c === '' || $c === false) break; $data .= $c; }
    if (strlen($mask) >= 4) { $o = ''; for ($i = 0; $i < strlen($data); $i++) { $o .= $data[$i] ^ $mask[$i % 4]; } $data = $o; }
    return json_decode($data, true);
}
$s = wsC($port);
wsS($s, json_encode(['v' => 2, 'id' => 'c1', 'type' => 'connect', 'ts' => (int) (microtime(true) * 1000), 'payload' => [
    'host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'password' => 'root', 'database' => 'shengyibao', 'serverId' => 0]]));
$meta = null;
$deadline = microtime(true) + 12;
while (microtime(true) < $deadline) {
    $f = wsR($s, 1);
    if ($f && ($f['type'] ?? '') === 'connected') { $meta = $f['payload'] ?? []; break; }
}
echo "connected: hasBinlog=" . var_export($meta['hasBinlog'] ?? null, true) . " pos=" . ($meta['binlogPos'] ?? '?') . "\n";
$file = $meta['binlogFile'] ?? 'binlog.000042';
$pos = $meta['binlogPos'] ?? 4;
wsS($s, json_encode(['v' => 2, 'id' => 'a6', 'type' => 'binlog-dump', 'ts' => (int) (microtime(true) * 1000), 'payload' => [
    'binlogFile' => $file, 'binlogPos' => $pos]]));
usleep(500000);
echo "[dump] pos=$pos 后发 query...\n";
wsS($s, json_encode(['v' => 2, 'id' => 'a7', 'type' => 'query', 'ts' => (int) (microtime(true) * 1000), 'payload' => ['sql' => 'SELECT 1 AS n']]));
$deadline = microtime(true) + 12;
$n = 0;
while (microtime(true) < $deadline && $n < 40) {
    $f = wsR($s, 1);
    if ($f === null) continue;
    $n++;
    echo "[frame] type=" . ($f['type'] ?? '?') . " id=" . ($f['id'] ?? '') . " payload=" . json_encode($f['payload'] ?? null, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
    if (($f['type'] ?? '') === 'query-result' || ($f['type'] ?? '') === 'error') break;
}
echo "[done]\n";
