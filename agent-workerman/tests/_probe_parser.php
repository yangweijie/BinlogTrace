<?php
// 临时探针：用 agent 抓取真实（含 CRC32 尾部）binlog 事件，喂给 parser 的 EventDecoder，
// 验证 CRC 是否破坏行解码 / 时间过滤逻辑。用法：php tests/_probe_parser.php
declare(strict_types=1);

$base = dirname(__DIR__);
$runtime = $base . '/runtime';
@mkdir($runtime, 0777, true);
$port = random_int(20000, 30000);
$logFile = $runtime . '/probe_parser_agent.log';

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
    echo "FAIL: agent 未就绪\n";
    exit(1);
}

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
    $second = ord($hdr[1]);
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
    return ['opcode' => $first = $second & 0x0F, 'payload' => $data];
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
$file = (string) ($meta['binlogFile'] ?? 'mysql-bin.000001');
sendFrame($s, ['v' => 2, 'id' => 'd1', 'type' => 'binlog-dump', 'ts' => (int) (microtime(true) * 1000), 'payload' => [
    'binlogFile' => $file, 'binlogPos' => 4, 'slaveFlags' => 0,
]]);

// 采集：首个 table_map（test.order）+ 紧随的 row 事件 + 其后的 xid
$captured = null; // {tm, row, xid}
$pendingKind = null;
$seen = 0;
$deadline = microtime(true) + 20;
$done = false;
while (microtime(true) < $deadline && !$done) {
    $f = wsReadFrame($s, 1);
    if ($f === null) {
        continue;
    }
    $d = json_decode($f['payload'], true);
    if (!is_array($d) || ($d['type'] ?? '') !== 'binlog-event') {
        if (is_array($d) && ($d['type'] ?? '') !== 'heartbeat' && $seen < 40) {
            echo "[frame] " . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        }
        continue;
    }
    $p = $d['payload'] ?? [];
    $type = (int) ($p['eventType'] ?? 0);
    if ($seen < 40) {
        echo "[evt] type=$type ts=" . ($p['timestamp'] ?? '?') . "\n";
    }
    $seen++;
    if ($type === 19) {
        // 解析表名，只要 test.order
        $raw = base64_decode((string) ($p['raw'] ?? ''));
        if (strlen($raw) >= 30) {
            $b = substr($raw, 19);
            $tid = LengthCodedRaw($b, 6);
            $off = 6 + 2;
            $dbLen = ord($b[$off] ?? "\0");
            $off++;
            $db = substr($b, $off, $dbLen);
            $off += $dbLen + 1;
            $tblLen = ord($b[$off] ?? "\0");
            $off++;
            $tbl = substr($b, $off, $tblLen);
            if ($db === 'test' && $tbl === 'order') {
                $captured = ['tm' => (string) ($p['raw'] ?? ''), 'row' => null, 'xid' => null];
                $pendingKind = 'row';
                echo "[capture] table_map test.order\n";
            }
        }
    } elseif ($captured !== null && $pendingKind === 'row' && in_array($type, [30, 31, 32], true)) {
        $captured['row'] = (string) ($p['raw'] ?? '');
        $pendingKind = 'xid';
        echo "[capture] row event type=$type raw=" . ($p['raw'] ?? '') . "\n";
    } elseif ($captured !== null && $pendingKind === 'xid' && $type === 16) {
        $captured['xid'] = (string) ($p['raw'] ?? '');
        $done = true;
    }
}
if ($captured === null || $captured['row'] === null || $captured['xid'] === null) {
    echo "FAIL: 未能采集到 test.order 的完整事件组（tm=" . ($captured['tm'] ?? '?') . " row=" . ($captured['row'] ?? '?') . " xid=" . ($captured['xid'] ?? '?') . "）\n";
    exit(1);
}
fclose($s);

// ── 用 parser 类解码 ──
spl_autoload_register(function (string $class): void {
    $prefix = 'Typephp\\BinlogParser\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $rel = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        $path = dirname(__DIR__, 2) . '/parser/src/' . $rel;
        if (is_file($path)) {
            require $path;
        }
    }
});

use Typephp\BinlogParser\Event\EventDecoder;
use Typephp\BinlogParser\Event\TableMapCache;

$cache = new TableMapCache();
$ok = true;
foreach (['tm' => 'TABLE_MAP', 'row' => 'ROW', 'xid' => 'XID'] as $key => $label) {
    $res = EventDecoder::decodeSingle($captured[$key], $cache);
    if ($key === 'tm' && isset($res['tableMap'])) {
        $cache->put((int) $res['tableMap']['tableId'], $res['tableMap']);
        echo "[parser] $label → schema={$res['tableMap']['schema']} table={$res['tableMap']['table']} cols={$res['tableMap']['columnCount']}\n";
    } else {
        echo "[parser] $label → kind={$res['kind']} warnings=" . json_encode($res['warnings'], JSON_UNESCAPED_UNICODE) . "\n";
    }
    if (($res['kind'] ?? 'skip') === 'skip' && $res['warnings'] !== []) {
        $ok = false;
    }
    if ($key === 'row' && ($res['rows'] ?? []) === []) {
        echo "[parser] ROW: 未解出任何行！\n";
        $ok = false;
    } else {
        echo "[parser] ROW: " . count($res['rows'] ?? []) . " 行; 首行=" . json_encode(($res['rows'][0] ?? null), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
    }
}
echo $ok ? "[RESULT] parser 解码通过\n" : "[RESULT] parser 解码存在问题\n";
exit($ok ? 0 : 1);

function LengthCodedRaw(string $b, int $n): int
{
    $v = 0;
    for ($i = 0; $i < $n; $i++) {
        $v |= ord($b[$i] ?? 0) << (8 * $i);
    }
    return $v;
}
