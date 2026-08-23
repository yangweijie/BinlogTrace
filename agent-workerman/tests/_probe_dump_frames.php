<?php
// 临时探针：验证 binlog-dump 组帧（无 0x00 错帧）+ 校验和尾部一致性
// 用法：php tests/_probe_dump_frames.php [binlogFile] [binlogPos]
declare(strict_types=1);

$base = dirname(__DIR__);
$runtime = $base . '/runtime';
@mkdir($runtime, 0777, true);
$port = random_int(20000, 30000);
$logFile = $runtime . '/probe_agent.log';

$proc = proc_open(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/start.php') . ' start --port ' . $port,
    [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']],
    $pipes,
    $base,
    null,
    ['bypass_shell' => true]
);
register_shutdown_function(function () use ($proc): void {
    if (DIRECTORY_SEPARATOR === '\\') {
        $st = proc_get_status($proc);
        if ($st['running']) {
            exec('taskkill /F /T /PID ' . (int) $st['pid'] . ' 2>nul');
        }
    } else {
        proc_terminate($proc);
    }
});
$ready = false;
for ($i = 0; $i < 100; $i++) {
    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);
    if ($s !== false) {
        fclose($s);
        $ready = true;
        break;
    }
    usleep(100_000);
}
if (!$ready) {
    echo "FAIL: agent 未就绪\n" . file_get_contents($logFile) . "\n";
    exit(1);
}
echo "[ok] agent 就绪 ({$port})\n";

function wsConnect(int $port)
{
    $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 5);
    if (!$s) {
        return false;
    }
    $key = base64_encode(random_bytes(16));
    $req = "GET / HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n";
    fwrite($s, $req);
    $resp = '';
    stream_set_timeout($s, 5);
    while (strpos($resp, "\r\n\r\n") === false && strlen($resp) < 4096) {
        $chunk = fread($s, 1024);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $resp .= $chunk;
    }
    if (stripos($resp, '101') === false) {
        fclose($s);
        return false;
    }
    return $s;
}

function wsSend($s, string $payload): void
{
    $len = strlen($payload);
    $mask = random_bytes(4);
    if ($len <= 125) {
        $header = "\x81" . chr(0x80 | $len);
    } elseif ($len <= 65535) {
        $header = "\x81" . chr(0x80 | 126) . pack('n', $len);
    } else {
        $header = "\x81" . chr(0x80 | 127) . pack('NN', 0, $len);
    }
    $masked = '';
    for ($i = 0; $i < $len; $i++) {
        $masked .= $payload[$i] ^ $mask[$i % 4];
    }
    fwrite($s, $header . $mask . $masked);
}

function wsReadFrame($s, float $timeout = 10): ?array
{
    stream_set_timeout($s, (int) floor($timeout), (int) (($timeout - floor($timeout)) * 1_000_000));
    $hdr = fread($s, 2);
    if ($hdr === false || strlen($hdr) < 2) {
        return null;
    }
    $first = ord($hdr[0]);
    $second = ord($hdr[1]);
    $opcode = $first & 0x0F;
    $len = $second & 0x7F;
    if ($len === 126) {
        $ext = fread($s, 2);
        $len = strlen($ext) >= 2 ? unpack('n', $ext)[1] : 0;
    } elseif ($len === 127) {
        $ext = fread($s, 8);
        if (strlen($ext) >= 8) {
            $len = unpack('J', $ext)[1];
        }
    }
    $mask = '';
    if (($second & 0x80) !== 0) {
        $mask = fread($s, 4);
    }
    $data = '';
    while (strlen($data) < $len) {
        $chunk = fread($s, $len - strlen($data));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    if (strlen($mask) >= 4) {
        $out = '';
        for ($i = 0; $i < strlen($data); $i++) {
            $out .= $data[$i] ^ $mask[$i % 4];
        }
        $data = $out;
    }
    return ['opcode' => $opcode, 'payload' => $data];
}

function sendFrame($s, array $frame): void
{
    wsSend($s, json_encode($frame, JSON_UNESCAPED_UNICODE));
}

$s = wsConnect($port);
if (!$s) {
    echo "FAIL: WS 连接失败\n";
    exit(1);
}
sendFrame($s, ['v' => 2, 'id' => 'c1', 'type' => 'connect', 'ts' => (int) (microtime(true) * 1000), 'payload' => [
    'host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'password' => 'root',
    'database' => 'shengyibao', 'serverId' => 0,
]]);

$meta = null;
$deadline = microtime(true) + 12;
while (microtime(true) < $deadline) {
    $f = wsReadFrame($s, 1);
    if ($f === null) {
        break;
    }
    $d = json_decode($f['payload'], true);
    if (is_array($d) && ($d['type'] ?? '') === 'connected') {
        $meta = $d['payload'] ?? [];
        break;
    }
}
if ($meta === null) {
    echo "FAIL: 未收到 connected\n";
    exit(1);
}
$file = $argv[1] ?? (string) ($meta['binlogFile'] ?? 'mysql-bin.000001');
$pos = isset($argv[2]) ? (int) $argv[2] : 4;
echo "[info] dump 起点 {$file} @ {$pos}\n";

sendFrame($s, ['v' => 2, 'id' => 'd1', 'type' => 'binlog-dump', 'ts' => (int) (microtime(true) * 1000), 'payload' => [
    'binlogFile' => $file, 'binlogPos' => $pos, 'slaveFlags' => 0,
]]);

$validTypes = [1, 2, 4, 5, 13, 14, 15, 16, 19, 27, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42];
$count = 0;
$bad = 0;
$tableMaps = [];
$deadline = microtime(true) + 15;
while (microtime(true) < $deadline && $count < 400) {
    $f = wsReadFrame($s, 1);
    if ($f === null) {
        continue;
    }
    $d = json_decode($f['payload'], true);
    if (!is_array($d)) {
        continue;
    }
    $ftype = (string) ($d['type'] ?? '?');
    if ($ftype === 'heartbeat') {
        continue;
    }
    if ($ftype !== 'binlog-event') {
        echo "[frame] type={$ftype} payload=" . json_encode($d['payload'] ?? null, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        continue;
    }
    $p = $d['payload'] ?? [];
    $raw = base64_decode((string) ($p['raw'] ?? ''));
    $len = strlen($raw);
    $count++;
    if ($len < 19) {
        $bad++;
        echo "[BAD] 事件#{$count}: raw < 19 字节 (len={$len})\n";
        continue;
    }
    $h = unpack('Vts/Ctype/Vserver/Vsize/Vpos/vflags', substr($raw, 0, 19));
    $type = (int) $h['type'];
    $size = (int) $h['size'];
    $ts = (int) $h['ts'];
    $okType = in_array($type, $validTypes, true);
    $okSize = $size === $len;
    $okTs = $ts > 0 && $ts < 4294967296;
    $mark = ($okType && $okSize && $okTs) ? 'ok ' : 'BAD';
    if (!$okType || !$okSize || !$okTs) {
        $bad++;
    }
    $extra = '';
    if ($count <= 8) {
        $hex = bin2hex(substr($raw, 0, 32));
        $extra = " hex=" . $hex;
    }
    if ($type === 19 && $len >= 30) {
        // TABLE_MAP: table_id(6)+flags(2) + lenenc db + lenenc table
        $p2 = 25;
        $dbLen = ord($raw[$p2]);
        $p2++;
        $db = $dbLen < 252 && $p2 + $dbLen <= $len ? substr($raw, $p2, $dbLen) : '?';
        $p2 += $dbLen;
        $tblLen = ord($raw[$p2] ?? "\0");
        $p2++;
        $tbl = $tblLen < 252 && $p2 + $tblLen <= $len ? substr($raw, $p2, $tblLen) : '?';
        $tableMaps[] = "{$db}.{$tbl}";
        $extra = " table_map={$db}.{$tbl}";
    }
    printf("[%s] #%d type=%-3d size=%d(len=%d) ts=%d first=0x%02X%s\n", $mark, $count, $type, $size, $len, $ts, ord($raw[0]), $extra);
}
echo "---\n[info] 共 {$count} 事件, {$bad} 个异常\n";
if ($tableMaps !== []) {
    echo "[info] table_map 列表: " . implode(', ', array_slice($tableMaps, 0, 20)) . "\n";
}
exit($bad > 0 ? 1 : 0);
