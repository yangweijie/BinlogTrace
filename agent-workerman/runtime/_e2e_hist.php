<?php
// 端到端（历史窗口）：WS connect → binlog-dump（startMs/endMs）→ 收集 binlog-change → binlog-end
$port = random_int(20000, 30000);
$logFile = dirname(__DIR__) . '/runtime/e2e_hist.log';
$proc = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/start.php') . ' start --port ' . $port,
    [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
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
function wsConnect(int $port) {
    $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $e, $es, 5);
    $key = base64_encode(random_bytes(16));
    fwrite($s, "GET / HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $resp = '';
    while (strpos($resp, "\r\n\r\n") === false && strlen($resp) < 4096) { $resp .= fread($s, 1024); }
    return $s;
}
function wsSend($s, string $p): void {
    $len = strlen($p); $mask = random_bytes(4);
    $h = $len <= 125 ? "\x81" . chr(0x80 | $len) : "\x81" . chr(0x80 | 126) . pack('n', $len);
    $m = ''; for ($i = 0; $i < $len; $i++) { $m .= $p[$i] ^ $mask[$i % 4]; }
    fwrite($s, $h . $mask . $m);
}
function wsRead($s, float $t = 2): ?array {
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
$s = wsConnect($port);
wsSend($s, json_encode(['v' => 2, 'id' => 'c1', 'type' => 'connect', 'ts' => (int) (microtime(true) * 1000), 'payload' => [
    'host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'password' => 'root', 'database' => 'shengyibao', 'serverId' => 0]]));
$meta = null;
$deadline = microtime(true) + 12;
while (microtime(true) < $deadline) {
    $f = wsRead($s, 1);
    if ($f && ($f['type'] ?? '') === 'connected') { $meta = $f['payload'] ?? []; break; }
}
if (!$meta) { echo "FAIL connected\n"; exit(1); }
// 历史窗口：覆盖 binlog.000042 中已知变更（ts≈1787414464~1787416133），前后各留余量
$startMs = 1787414400 * 1000;
$endMs = 1787416200 * 1000;
wsSend($s, json_encode(['v' => 2, 'id' => 'd1', 'type' => 'binlog-dump', 'ts' => (int) (microtime(true) * 1000), 'payload' => [
    'binlogFile' => $meta['binlogFile'] ?? 'binlog.000042', 'binlogPos' => 4,
    'startMs' => $startMs, 'endMs' => $endMs]]));
$changes = [];
$ended = false;
$deadline = microtime(true) + 40; // 心跳 5s 兜底退出，给足余量
while (microtime(true) < $deadline && !$ended) {
    $f = wsRead($s, 2);
    if (!$f) continue;
    $t = $f['type'] ?? '';
    if ($t === 'binlog-change') {
        $changes[] = $f['payload'] ?? [];
        echo "[change] " . json_encode(['kind' => $f['payload']['kind'] ?? '', 'table' => ($f['payload']['table'] ?? '') . ' ' . ($f['payload']['after']['total_amount'] ?? '')], JSON_UNESCAPED_UNICODE) . "\n";
    } elseif ($t === 'binlog-end') {
        $ended = true;
        echo "[ok] binlog-end\n";
    } elseif ($t === 'error') {
        echo "[error] " . json_encode($f['payload'], JSON_UNESCAPED_UNICODE) . "\n";
        break;
    }
}
$inWindow = array_filter($changes, fn ($c) => ($c['timestamp'] ?? 0) >= 1787414500 && ($c['timestamp'] ?? 0) <= 1787416133);
echo count($inWindow) > 0 && $ended ? "\n[RESULT] 通过：窗口内 " . count($inWindow) . " 条变更 + binlog-end\n" : "\n[RESULT] 失败 changes=" . count($changes) . " ended=" . var_export($ended, true) . "\n";
exit(count($inWindow) > 0 && $ended ? 0 : 1);
