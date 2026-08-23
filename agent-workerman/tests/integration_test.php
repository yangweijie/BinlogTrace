<?php
// 实时集成测试：连真实 MySQL（127.0.0.1:3306 root/root shengyibao）
// 自动拉起 agent → connect → query → binlog 信息，验证成功路径
$base = dirname(__DIR__);
$runtime = $base . '/runtime';
@mkdir($runtime, 0777, true);

$port = random_int(20000, 30000);
$logFile = $runtime . '/itg_agent.log';

function killTree($proc): void
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
    $dl = microtime(true) + 3;
    do {
        usleep(100_000);
        $st = proc_get_status($proc);
    } while ($st['running'] && microtime(true) < $dl);
}

$proc = proc_open(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/start.php') . ' start --port ' . $port,
    [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']],
    $pipes,
    $base,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($proc)) {
    echo "FAIL: 无法启动 agent\n";
    exit(1);
}
register_shutdown_function(function () use ($proc): void {
    killTree($proc);
    proc_terminate($proc);
});

// 等待就绪
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

// ── WS 工具（复制自 ws_smoke_test.php）──
function wsConnect(int $port)
{
    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 5);
    if (!$s) {
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
    if (stripos($resp, '101') === false || stripos($resp, 'Sec-WebSocket-Accept:') === false) {
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
    $masked = ($second & 0x80) !== 0;
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
    if ($masked) {
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
    if ($masked && strlen($mask) >= 4) {
        $out = '';
        for ($i = 0; $i < strlen($data); $i++) {
            $out .= $data[$i] ^ $mask[$i % 4];
        }
        $data = $out;
    }
    return ['opcode' => $opcode, 'payload' => $data];
}

function nowMs(): int
{
    return (int) (microtime(true) * 1000);
}

/** 发送一帧并读取非心跳响应 */
function request(int $port, array $frame, float $timeout = 12): ?array
{
    $s = wsConnect($port);
    if (!$s) {
        return null;
    }
    wsSend($s, json_encode($frame, JSON_UNESCAPED_UNICODE));
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $f = wsReadFrame($s, max(0.2, $deadline - microtime(true)));
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

/** 在同一 WS 连接上发送一帧并读取非心跳响应 */
function requestOn($s, array $frame, float $timeout = 12): ?array
{
    wsSend($s, json_encode($frame, JSON_UNESCAPED_UNICODE));
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $f = wsReadFrame($s, max(0.2, $deadline - microtime(true)));
        if ($f === null) {
            break;
        }
        $decoded = json_decode($f['payload'], true);
        if (is_array($decoded) && ($decoded['type'] ?? '') !== 'heartbeat') {
            return $decoded;
        }
    }
    return null;
}

/** 在同一 WS 连接上发送一帧并读取指定类型的响应（跳过 heartbeat / binlog-event / binlog-change 流帧，dump 期间 query 用） */
function requestOnFor($s, array $frame, string $wantType, float $timeout = 12): ?array
{
    wsSend($s, json_encode($frame, JSON_UNESCAPED_UNICODE));
    $id = (string) ($frame['id'] ?? '');
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $f = wsReadFrame($s, max(0.2, $deadline - microtime(true)));
        if ($f === null) {
            break;
        }
        $decoded = json_decode($f['payload'], true);
        if (!is_array($decoded)) {
            continue;
        }
        $t = (string) ($decoded['type'] ?? '');
        if ($t === 'heartbeat' || $t === 'binlog-event' || $t === 'binlog-change') {
            continue;
        }
        if ($t === $wantType || $t === 'error' || ($id !== '' && ($decoded['id'] ?? '') === $id)) {
            return $decoded;
        }
    }
    return null;
}

// ── 用例 A-D：同一 WS 连接上 connect → 各查询 ──
// ── 用例 A0：空库 connect（回归：CONNECT_WITH_DB 置位但库名字段缺失时，
// 服务端把插件名误读为库名 → Unknown database 'mysql_native_password'）──
$s0 = wsConnect($port);
if (!$s0) {
    check('WS 连接建立（空库组）', false, '无法连接 agent');
} else {
    check('WS 连接建立（空库组）', true);
    $r0 = requestOn($s0, ['v' => 2, 'id' => 'a0', 'type' => 'connect', 'ts' => nowMs(), 'payload' => [
        'host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'password' => 'root',
        'database' => '', 'serverId' => 887,
    ]], 10);
    check('空库 connect → connected（认证包库名字段正确）', is_array($r0) && ($r0['type'] ?? '') === 'connected' && ($r0['payload']['serverVersion'] ?? '') !== '', json_encode($r0, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    $r0b = requestOn($s0, ['v' => 2, 'id' => 'a0b', 'type' => 'query', 'ts' => nowMs(), 'payload' => ['sql' => 'SELECT 42 AS v']], 10);
    check('空库 connect 后 query 可用', is_array($r0b) && ($r0b['type'] ?? '') === 'query-result' && ($r0b['payload']['rows'][0][0] ?? null) === '42', json_encode($r0b, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    fclose($s0);
}

// ── 用例 A-D：同一 WS 连接上 connect → 各查询 ──
$s = wsConnect($port);
if (!$s) {
    check('WS 连接建立', false, '无法连接 agent');
} else {
    check('WS 连接建立', true);

    // A：connect → connected
    $r = requestOn($s, ['v' => 2, 'id' => 'a1', 'type' => 'connect', 'ts' => nowMs(), 'payload' => [
        'host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'password' => 'root',
        'database' => 'shengyibao', 'connectTimeoutMs' => 5000, 'serverId' => 0,
    ]], 12);
    check('connect 真实 MySQL → connected', is_array($r) && $r['type'] === 'connected', json_encode($r, JSON_UNESCAPED_UNICODE));
    if (is_array($r) && $r['type'] === 'connected') {
        $meta = $r['payload'] ?? [];
        echo '    元数据: serverVersion=' . ($meta['serverVersion'] ?? '?') . ' hasBinlog=' . var_export($meta['hasBinlog'] ?? null, true)
            . ' binlogFile=' . ($meta['binlogFile'] ?? '?') . PHP_EOL;

        // B：query SELECT 1
        $r2 = requestOn($s, ['v' => 2, 'id' => 'a2', 'type' => 'query', 'ts' => nowMs(), 'payload' => ['sql' => 'SELECT 1 AS n']], 10);
        check('query SELECT 1 → query-result', is_array($r2) && $r2['type'] === 'query-result' && ($r2['payload']['rows'][0][0] ?? null) === '1', json_encode($r2, JSON_UNESCAPED_UNICODE));

        // C：query 只读校验（INSERT 应被拒 1010）
        $r3 = requestOn($s, ['v' => 2, 'id' => 'a3', 'type' => 'query', 'ts' => nowMs(), 'payload' => ['sql' => 'INSERT INTO x VALUES (1)']], 5);
        check('INSERT 被拒 → 1010', is_array($r3) && $r3['type'] === 'error' && ($r3['payload']['code'] ?? 0) === 1010, json_encode($r3, JSON_UNESCAPED_UNICODE));

        // D：query 真实业务库表
        $r4 = requestOn($s, ['v' => 2, 'id' => 'a4', 'type' => 'query', 'ts' => nowMs(), 'payload' => ['sql' => 'SHOW TABLES']], 10);
        check('SHOW TABLES → query-result', is_array($r4) && $r4['type'] === 'query-result', json_encode($r4, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        if (is_array($r4) && $r4['type'] === 'query-result') {
            echo '    表数量: ' . count($r4['payload']['rows'] ?? []) . PHP_EOL;
            foreach (array_slice($r4['payload']['rows'] ?? [], 0, 5) as $row) {
                echo '    - ' . ($row[0] ?? '') . PHP_EOL;
            }
        }

        // E：query 带显式 database 字段（前端 useSchemaMeta 查表时传目标库；
        // 代理曾丢弃该字段——回归：显式库下仍应返回表清单）
        $r5 = requestOn($s, ['v' => 2, 'id' => 'a5', 'type' => 'query', 'ts' => nowMs(), 'payload' => [
            'sql' => 'SHOW TABLES', 'database' => 'shengyibao',
        ]], 10);
        check('query 带 database 字段 → query-result', is_array($r5) && $r5['type'] === 'query-result' && is_array($r5['payload']['rows'] ?? null), json_encode($r5, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        // F：binlog-dump 长驻期间 query（回归：旧实现共用单连接 ST_BINLOG 会报 1006「busy 或未连接」；
        // 现在 query 走独立连接，dump 期间必须能返回 query-result）
        $hasRepSlave = in_array('REPLICATION SLAVE', (array) ($meta['userPrivileges'] ?? []), true);
        if (!$hasRepSlave) {
            echo '[SKIP] 用例 F（dump 期间 query）：账号缺 REPLICATION SLAVE 权限，无法验证\n';
        } else {
            $dumpFile = $meta['binlogFile'] !== null ? (string) $meta['binlogFile'] : 'mysql-bin.000001';
            $dumpPos = $meta['binlogPos'] !== null ? (int) $meta['binlogPos'] : 4;
            $rd = requestOnFor($s, ['v' => 2, 'id' => 'a6', 'type' => 'binlog-dump', 'ts' => nowMs(), 'payload' => [
                'binlogFile' => $dumpFile, 'binlogPos' => $dumpPos, 'slaveFlags' => 0,
            ]], 10);
            $dumpStarted = is_array($rd) && ($rd['type'] ?? '') === 'dump-started';
            check('binlog-dump 启动 → dump-started', $dumpStarted, json_encode($rd, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            if ($dumpStarted) {
                usleep(300_000); // 让事件流先跑起来，确保主连接进入 ST_BINLOG
                $r6 = requestOnFor($s, ['v' => 2, 'id' => 'a7', 'type' => 'query', 'ts' => nowMs(), 'payload' => [
                    'sql' => 'SELECT 1 AS n',
                ]], 10);
                check('dump 期间 query → query-result（不报 1006）', is_array($r6) && ($r6['type'] ?? '') === 'query-result' && ($r6['payload']['rows'][0][0] ?? null) === '1', json_encode($r6, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    fclose($s);
}

echo "\n结果: {$pass} PASS / {$fail} FAIL\n";
echo "--- agent log ---\n" . trim((string) file_get_contents($logFile)) . "\n";
exit($fail > 0 ? 1 : 0);
