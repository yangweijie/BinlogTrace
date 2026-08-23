<?php
// 手动复现：完整握手 + 认证交换，逐包 dump 服务端响应
$errno = 0;
$errstr = '';
$s = @stream_socket_client('tcp://127.0.0.1:3306', $errno, $errstr, 5);
if (!$s) {
    echo "connect fail\n";
    exit(1);
}
stream_set_timeout($s, 5);

function readPacket($s): ?string
{
    $hdr = fread($s, 4);
    if ($hdr === false || strlen($hdr) < 4) {
        return null;
    }
    $len = ord($hdr[0]) | (ord($hdr[1]) << 8) | (ord($hdr[2]) << 16);
    $seq = ord($hdr[3]);
    $data = '';
    while (strlen($data) < $len) {
        $chunk = fread($s, $len - strlen($data));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    echo "  <- pkt seq={$seq} len={$len} first=0x" . strtoupper(dechex(ord($data[0] ?? "\xff"))) . PHP_EOL;
    if ($data !== '') {
        echo "     hex: " . bin2hex(substr($data, 0, 80)) . PHP_EOL;
    }
    return $data;
}

function sendPacket($s, string $payload, int $seq): void
{
    $len = strlen($payload);
    $hdr = chr($len & 0xFF) . chr(($len >> 8) & 0xFF) . chr(($len >> 16) & 0xFF) . chr($seq & 0xFF);
    fwrite($s, $hdr . $payload);
    echo "  -> pkt seq={$seq} len={$len}\n";
}

// 1. 握手
$hs = readPacket($s);
if ($hs === null || strlen($hs) < 33) {
    echo "握手包异常\n";
    exit(1);
}
$verLen = strpos($hs, "\0", 1);
echo "server version: " . substr($hs, 1, $verLen - 1) . PHP_EOL;
$authData1 = substr($hs, $verLen + 5, 8);
$lowCap = unpack('v', substr($hs, $verLen + 14, 2))[1];
$highCap = unpack('v', substr($hs, $verLen + 19, 2))[1];
$caps = ($highCap << 16) | $lowCap;
$authDataLen = ord($hs[$verLen + 21]);
$authData2 = $authDataLen > 8 ? substr($hs, $verLen + 32, $authDataLen - 8) : '';
$scramble = $authData1 . $authData2;
$charset = ord($hs[$verLen + 16]);
echo "caps=0x" . strtoupper(dechex($caps)) . " authDataLen={$authDataLen} scrambleLen=" . strlen($scramble) . PHP_EOL;

// 2. 认证（mysql_native_password，规范能力集）
$user = 'root';
$password = 'root';
$database = 'shengyibao';
$CAP = 0x000FA20F;
$sha1Pwd = hash('sha1', $password, true);
$auth = $sha1Pwd ^ hash('sha1', $sha1Pwd . $scramble, true);
$pkt = pack('V', $CAP) . pack('V', 16777215) . chr($charset) . str_repeat("\0", 23)
    . $user . "\0"
    . chr(strlen($auth)) . $auth
    . $database . "\0"
    . "mysql_native_password\0";
sendPacket($s, $pkt, 1);

// 3. 读认证响应（可能多包：AuthSwitch → 响应 → OK）
$resp = readPacket($s);
while ($resp !== null) {
    $first = ord($resp[0]);
    if ($first === 0x00) {
        echo "AUTH OK\n";
        break;
    }
    if ($first === 0xFF) {
        echo "AUTH ERR: " . substr($resp, 9) . "\n";
        break;
    }
    if ($first === 0xFE) {
        // AuthSwitch：解析插件 + scramble
        $pos = 1;
        $plugin = '';
        while ($pos < strlen($resp) && $resp[$pos] !== "\0") {
            $plugin .= $resp[$pos];
            $pos++;
        }
        $pos++;
        $newScramble = substr($resp, $pos);
        echo "AUTH SWITCH to {$plugin} (scramble=" . strlen($newScramble) . ")\n";
        if ($plugin === 'caching_sha2_password') {
            $h1 = hash('sha256', $password, true);
            $h2 = hash('sha256', $h1, true);
            $h3 = hash('sha256', $h2 . $newScramble, true);
            $resp2 = $h3 ^ $h1;
            sendPacket($s, $resp2, 3);
        } else {
            echo "unknown plugin\n";
            break;
        }
        $resp = readPacket($s);
        continue;
    }
    if ($first === 0x01) {
        $code = ord($resp[1] ?? 0);
        if ($code === 0x03) {
            echo "caching_sha2 FAST AUTH SUCCESS\n";
            $resp = readPacket($s);
            continue;
        }
        if ($code === 0x04) {
            echo "caching_sha2 FULL AUTH REQUIRED — 请求公钥\n";
            sendPacket($s, "\x02", 5);
            $resp = readPacket($s);
            continue;
        }
        // 其余 0x01 开头的 AuthMoreData 即 RSA 公钥（PEM 直接跟在 0x01 后）
        $pem = substr($resp, 1);
        echo "收到 RSA 公钥 (" . strlen($pem) . " bytes), 头部: " . substr($pem, 0, 40) . PHP_EOL;
        $clean = preg_replace('/-----BEGIN[^-]+-----|-----END[^-]+-----|\s+/', '', $pem);
        $pubKey = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($clean, 64, "\n") . "-----END PUBLIC KEY-----\n";
        $cleartext = '';
        for ($i = 0; $i < strlen($password); $i++) {
            $cleartext .= $password[$i] ^ $newScramble[$i % strlen($newScramble)];
        }
        $cleartext .= "\0";
        $enc = '';
        $ok = openssl_public_encrypt($cleartext, $enc, $pubKey, OPENSSL_PKCS1_OAEP_PADDING);
        echo "RSA encrypt: " . ($ok ? 'OK (' . strlen($enc) . ' bytes)' : 'FAIL') . PHP_EOL;
        sendPacket($s, $enc, 7);
        $resp = readPacket($s);
        continue;
    }
    echo "UNEXPECTED first=0x" . dechex($first) . "\n";
    break;
}

// 4. 若认证成功，发一个 COM_QUERY
if ($resp !== null && ord($resp[0]) === 0x00) {
    sendPacket($s, chr(3) . 'SELECT 1', 0);
    $r = readPacket($s);
    if ($r !== null && ord($r[0]) !== 0xFF) {
        $cnt = $r;
        $fieldCount = ord($cnt[0]);
        echo "query OK, fieldCount={$fieldCount}\n";
        for ($i = 0; $i < $fieldCount; $i++) {
            readPacket($s);
        }
        readPacket($s); // EOF
        $row = readPacket($s);
        echo "row: " . bin2hex($row ?? '') . "\n";
    } else {
        echo "query ERR\n";
    }
}

fclose($s);
