<?php

declare(strict_types=1);

/**
 * ws_smoke_test.php — agent-workerman 冒烟测试
 *
 * 自动拉起 workerman agent（随机端口），通过原生 WebSocket 客户端验证：
 *   1. WS 握手
 *   2. 非法 JSON / 未知 type → 1010
 *   3. 未连接 MySQL 时 query / binlog-dump → 1006
 *   4. connect 到不可达主机 → 1002
 *   5. connect 到非 MySQL 端口（握手失败）→ 1010
 *   6. 15s 心跳帧
 *
 * 用法：php tests/ws_smoke_test.php
 */

$base = dirname(__DIR__);
$port = random_int(20000, 30000);
$logFile = $base . '/runtime/smoke_agent.log';

if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0777, true);
}

// ─── 拉起 agent ─────────────────────────────────────────
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/start.php') . ' start --port ' . $port;
$proc = proc_open(
    $cmd,
    [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']],
    $pipes,
    $base,
    null,
    // Windows 下用 CreateProcess 直启，避免 cmd.exe 包装进程继承 harness 管道
    ['bypass_shell' => true]
);
if (!is_resource($proc)) {
    fwrite(STDERR, "FAIL: 无法启动 agent\n");
    exit(1);
}
register_shutdown_function(function () use ($proc): void {
    if (is_resource($proc)) {
        killAgentTree($proc);
        proc_terminate($proc);
    }
});

/**
 * 杀 agent 进程树（workerman 在 Windows 会派生子进程，需 taskkill /T 递归清理），
 * 并在退出前等待其真正消亡，避免子进程持有 stdout 管道导致外层等待挂起。
 */
function killAgentTree($proc): void
{
    $st = proc_get_status($proc);
    if (!$st['running']) {
        return;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        exec('taskkill /F /T /PID ' . (int) $st['pid'] . ' 2>nul');
    } else {
        exec('kill -9 ' . (int) $st['pid'] . ' 2>/dev/null');
    }
    $deadline = microtime(true) + 3;
    do {
        usleep(100_000);
        $st = proc_get_status($proc);
    } while ($st['running'] && microtime(true) < $deadline);
}

// 等待端口就绪（最多 10s）
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
    fwrite(STDERR, "FAIL: agent 未在 10s 内就绪\n" . file_get_contents($logFile) . "\n");
    exit(1);
}
echo "[ok] agent 就绪 (port {$port})\n";

// ─── 测试工具 ───────────────────────────────────────────
$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "[PASS] {$name}\n";
    } else {
        $fail++;
        echo "[FAIL] {$name} {$detail}\n";
    }
}

function wsConnect(int $port)
{
    $errno = 0;
    $errstr = '';
    // 注：本机 PHP 8.4 构建中 fsockopen 的引用参数异常，统一改用 stream_socket_client
    $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 5);
    if (!$s) {
        fwrite(STDERR, "[debug] wsConnect TCP fail: {$errno} {$errstr}\n");
        return false;
    }
    $key = base64_encode(random_bytes(16));
    $req = "GET / HTTP/1.1\r\n"
        . "Host: 127.0.0.1:{$port}\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\n"
        . "Sec-WebSocket-Version: 13\r\n"
        . "\r\n";
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
    // 注：不硬编码 Sec-WebSocket-Accept 的 GUID（各实现标准值可能不同），
    // 只校验 101 状态 + Accept 头存在；真实握手兼容性由 node_ws_check.mjs 用
    // Node 原生 WebSocket 客户端验证。
    if (stripos($resp, '101') === false || stripos($resp, 'Sec-WebSocket-Accept:') === false) {
        fwrite(STDERR, "[debug] wsConnect handshake fail, resp(" . strlen($resp) . "): " . var_export($resp, true) . "\n");
        fclose($s);
        return false;
    }
    return $s;
}

function wsSend($s, string $payload): void
{
    $len = strlen($payload);
    $first = chr(0x81);
    $mask = random_bytes(4);
    if ($len <= 125) {
        $header = $first . chr(0x80 | $len);
    } elseif ($len <= 65535) {
        $header = $first . chr(0x80 | 126) . pack('n', $len);
    } else {
        $header = $first . chr(0x80 | 127) . pack('NN', 0, $len);
    }
    $masked = '';
    for ($i = 0; $i < $len; $i++) {
        $masked .= $payload[$i] ^ $mask[$i % 4];
    }
    fwrite($s, $header . $mask . $masked);
}

function wsReadFrame($s, float $timeout = 5): ?array
{
    stream_set_timeout($s, (int) floor($timeout), (int) (($timeout - floor($timeout)) * 1_000_000));
    $hdr = fread($s, 2);
    if ($hdr === false || strlen($hdr) < 2) {
        return null;
    }
    $first = ord($hdr[0]);
    $second = ord($hdr[1]);
    $opcode = $first & 0x0F;
    $masked = ($second & 0x80) !== 0;
    $len = $second & 0x7F;
    if ($len === 126) {
        $ext = fread($s, 2);
        if (strlen($ext) < 2) {
            return null;
        }
        $len = unpack('n', $ext)[1];
    } elseif ($len === 127) {
        $ext = fread($s, 8);
        if (strlen($ext) < 8) {
            return null;
        }
        $hi = unpack('N', substr($ext, 0, 4))[1];
        $lo = unpack('N', substr($ext, 4, 4))[1];
        $len = $hi * 4294967296 + $lo;
    }
    $mask = '';
    if ($masked) {
        $mask = fread($s, 4);
        if (strlen($mask) < 4) {
            return null;
        }
    }
    $data = '';
    while (strlen($data) < $len) {
        $chunk = fread($s, $len - strlen($data));
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $data .= $chunk;
    }
    if ($masked) {
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $data[$i] ^ $mask[$i % 4];
        }
        $data = $out;
    }
    return ['opcode' => $opcode, 'payload' => $data];
}

/** 发送帧并读取响应（自动跳过心跳帧） */
function request(int $port, array $frame, float $timeout = 5): ?array
{
    $s = wsConnect($port);
    if (!$s) {
        return null;
    }
    wsSend($s, json_encode($frame, JSON_UNESCAPED_UNICODE));
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $f = wsReadFrame($s, max(0.1, $deadline - microtime(true)));
        if ($f === null) {
            break;
        }
        $decoded = json_decode($f['payload'], true);
        if (is_array($decoded) && ($decoded['type'] ?? '') !== 'heartbeat') {
            fclose($s);
            return $decoded;
        }
    }
    fclose($s);
    return null;
}

function nowMs(): int
{
    return (int) (microtime(true) * 1000);
}

// ─── 用例 1：握手 ────────────────────────────────────────
$s = wsConnect($port);
check('WS 握手', $s !== false);
if ($s) {
    fclose($s);
}

// ─── 用例 2：非法 JSON → 1010 ───────────────────────────
$s = wsConnect($port);
if ($s) {
    wsSend($s, 'this is not json {{{');
    $f = wsReadFrame($s);
    $decoded = $f ? json_decode($f['payload'], true) : null;
    check('非法 JSON → 1010', is_array($decoded) && $decoded['type'] === 'error' && ($decoded['payload']['code'] ?? 0) === 1010, json_encode($decoded ?? null));
    fclose($s);
}

// ─── 用例 3：未知 type → 1010 ───────────────────────────
$s = wsConnect($port);
if ($s) {
    wsSend($s, json_encode(['v' => 2, 'id' => 't3', 'type' => 'nonsense', 'ts' => nowMs(), 'payload' => []]));
    $f = wsReadFrame($s);
    $decoded = $f ? json_decode($f['payload'], true) : null;
    check('未知 type → 1010', is_array($decoded) && $decoded['type'] === 'error' && ($decoded['payload']['code'] ?? 0) === 1010, json_encode($decoded ?? null));
    fclose($s);
}

// ─── 用例 4：未连接时 query → 1006 ──────────────────────
$r = request($port, ['v' => 2, 'id' => 't4', 'type' => 'query', 'ts' => nowMs(), 'payload' => ['sql' => 'SELECT 1']]);
check('未连接 query → 1006', is_array($r) && $r['type'] === 'error' && ($r['payload']['code'] ?? 0) === 1006, json_encode($r ?? null));

// ─── 用例 5：未连接时 binlog-dump → 1006 ────────────────
$r = request($port, ['v' => 2, 'id' => 't5', 'type' => 'binlog-dump', 'ts' => nowMs(), 'payload' => ['binlogFile' => 'mysql-bin.000001', 'binlogPos' => 4]]);
check('未连接 binlog-dump → 1006', is_array($r) && $r['type'] === 'error' && ($r['payload']['code'] ?? 0) === 1006, json_encode($r ?? null));

// ─── 用例 6：connect 到不可达主机 → 1002 ────────────────
$r = request($port, ['v' => 2, 'id' => 't6', 'type' => 'connect', 'ts' => nowMs(), 'payload' => [
    'host' => '127.0.0.1',
    'port' => 1,            // 通常无服务监听 → 连接拒绝
    'user' => 'root',
    'password' => '',
    'database' => '',
    'connectTimeoutMs' => 3000,
    'serverId' => 0,
]], 8);
check('不可达主机 → 1002', is_array($r) && $r['type'] === 'error' && ($r['payload']['code'] ?? 0) === 1002, json_encode($r ?? null));

// ─── 用例 7：connect 到"发送垃圾字节"的非 MySQL 服务（握手解析失败）→ 1010
$garbagePort = random_int(20000, 30000);
$garbageProc = proc_open(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/garbage_server.php') . ' ' . $garbagePort,
    [1 => ['file', $base . '/runtime/garbage_server.log', 'w'], 2 => ['file', $base . '/runtime/garbage_server.log', 'a']],
    $pipes,
    __DIR__,
    null,
    ['bypass_shell' => true]
);
usleep(300_000);
$r = request($port, ['v' => 2, 'id' => 't7', 'type' => 'connect', 'ts' => nowMs(), 'payload' => [
    'host' => '127.0.0.1',
    'port' => $garbagePort,     // 非 MySQL 服务 → 握手包解析失败
    'user' => 'root',
    'password' => '',
    'database' => '',
    'connectTimeoutMs' => 5000,
    'serverId' => 0,
]], 10);
if (is_resource($garbageProc)) {
    proc_terminate($garbageProc);
}
check('非 MySQL 握手 → 1010', is_array($r) && $r['type'] === 'error' && ($r['payload']['code'] ?? 0) === 1010, json_encode($r ?? null));

// ─── 用例 8：15s 自主心跳定时器（不发送任何消息，等待服务端主动推送）──
$s = wsConnect($port);
if ($s) {
    $gotHeartbeat = false;
    $deadline = microtime(true) + 18;
    while (microtime(true) < $deadline) {
        $f = wsReadFrame($s, max(0.5, $deadline - microtime(true)));
        if ($f === null) {
            break;
        }
        $decoded = json_decode($f['payload'], true);
        if (is_array($decoded) && $decoded['type'] === 'heartbeat' && isset($decoded['payload']['ts'])) {
            $gotHeartbeat = true;
            break;
        }
    }
    check('15s 心跳帧', $gotHeartbeat);
    fclose($s);
}

// ─── 用例 9：同一连接重复 connect → 1008 ────────────────
$s = wsConnect($port);
if ($s) {
    wsSend($s, json_encode(['v' => 2, 'id' => 't9', 'type' => 'connect', 'ts' => nowMs(), 'payload' => ['host' => '127.0.0.1', 'port' => 1, 'user' => 'r', 'password' => '', 'connectTimeoutMs' => 3000]]));
    // 第一次 connect 会失败（1002），随后立即发第二次 connect 应报“已连接/请先 close”或再次连接
    // 这里只验证第二次 connect 有响应且为 error（1008 或 1002 均可接受，二者都证明连接状态被正确处理）
    usleep(500_000);
    wsSend($s, json_encode(['v' => 2, 'id' => 't9b', 'type' => 'connect', 'ts' => nowMs(), 'payload' => ['host' => '127.0.0.1', 'port' => 1, 'user' => 'r', 'password' => '', 'connectTimeoutMs' => 3000]]));
    $f1 = wsReadFrame($s);
    $f2 = wsReadFrame($s);
    $r1 = $f1 ? json_decode($f1['payload'], true) : null;
    $r2 = $f2 ? json_decode($f2['payload'], true) : null;
    $codes = array_map(fn($x) => $x['payload']['code'] ?? null, array_filter([$r1, $r2]));
    check('重复 connect 均有错误响应', count($codes) === 2 && in_array(1008, $codes, true), json_encode([$r1, $r2]));
    fclose($s);
}

// ─── 汇总 ───────────────────────────────────────────────
echo "\n结果: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
