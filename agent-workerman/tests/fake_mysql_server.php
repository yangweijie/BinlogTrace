<?php
// 假 MySQL 服务端：捕获 mysqlnd（PDO）在 caching_sha2 完整认证时发送的精确字节
// 用法：另开终端运行本脚本，然后运行 tests/pdo_fake_connect.php

$port = 3399;
$errno = 0;
$errstr = '';
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if (!$server) {
    echo "listen fail: $errstr\n";
    exit(1);
}
echo "假 MySQL 监听 {$port}\n";

// 生成 RSA 密钥对
$res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$details = openssl_pkey_get_details($res);
$pubPem = $details['key'];
openssl_pkey_export($res, $privPem);

$conn = stream_socket_accept($server, 30);
if (!$conn) {
    echo "no client\n";
    exit(1);
}
stream_set_timeout($conn, 10);
echo "客户端已连接\n";

function readPkt($conn): ?string
{
    $hdr = fread($conn, 4);
    if ($hdr === false || strlen($hdr) < 4) {
        return null;
    }
    $len = ord($hdr[0]) | (ord($hdr[1]) << 8) | (ord($hdr[2]) << 16);
    $seq = ord($hdr[3]);
    $data = '';
    while (strlen($data) < $len) {
        $chunk = fread($conn, $len - strlen($data));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    echo "  <- client seq={$seq} len={$len} first=0x" . strtoupper(dechex(ord($data[0] ?? 0))) . PHP_EOL;
    if ($data !== '') {
        echo "     hex: " . bin2hex(substr($data, 0, 100)) . PHP_EOL;
    }
    return $data;
}

function sendPkt($conn, string $payload, int $seq): void
{
    $len = strlen($payload);
    $hdr = chr($len & 0xFF) . chr(($len >> 8) & 0xFF) . chr(($len >> 16) & 0xFF) . chr($seq & 0xFF);
    fwrite($conn, $hdr . $payload);
    echo "  -> server seq={$seq} len={$len}\n";
}

// 1. v10 握手（模仿 MySQL 8.0.36，caching_sha2）
$scramble = '12345678ABCDEFGHijkl' . "\x00"; // 20 字节 + NUL = 21
$authData2 = substr($scramble, 8, 13); // auth-plugin-data-part-2 = 13 字节（authDataLen 21 - 8）
$cap = 0xDFFFFFFF;
$hs = chr(10) . '8.0.36' . "\0"
    . pack('V', 1)                       // connection id
    . substr($scramble, 0, 8)            // auth-plugin-data-part-1
    . "\0"                               // filler
    . pack('v', $cap & 0xFFFF)           // capability flags lower
    . chr(0xff)                          // character set
    . pack('v', 2)                       // status flags
    . pack('v', ($cap >> 16) & 0xFFFF)   // capability flags upper
    . chr(21)                            // auth data length
    . str_repeat("\0", 10)               // reserved
    . 'caching_sha2_password' . "\0"     // auth plugin name
    . $authData2;                        // auth-plugin-data-part-2
sendPkt($conn, $hs, 0);

// 2. 读客户端认证响应
$resp = readPkt($conn);

// 3. AuthSwitch → caching_sha2
$switch = "\xfe" . 'caching_sha2_password' . "\0" . $scramble;
sendPkt($conn, $switch, 2);

// 4. 读 sha2 快速响应（32 字节）
$fast = readPkt($conn);
echo '快速响应长度: ' . strlen($fast ?? '') . PHP_EOL;

// 5. 要求完整认证
sendPkt($conn, "\x01\x04", 4);

// 6. 读公钥请求
$req = readPkt($conn);
echo '公钥请求: 0x' . bin2hex($req ?? '') . PHP_EOL;

// 7. 发送 RSA 公钥（完整 PEM，模仿真实服务端格式）
$keyPkt = "\x01" . $pubPem;
sendPkt($conn, $keyPkt, 6);

// 8. 读加密口令
$enc = readPkt($conn);
echo '加密口令长度: ' . strlen($enc ?? '') . PHP_EOL;

// 9. 用私钥解密，dump 明文
if ($enc !== null && $enc !== '') {
    $dec = '';
    $ok = openssl_private_decrypt($enc, $dec, $privPem, OPENSSL_PKCS1_OAEP_PADDING);
    echo 'RSA 解密: ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    echo '明文 hex: ' . bin2hex($dec) . PHP_EOL;
    echo '明文 ascii: [' . $dec . ']' . PHP_EOL;
    // 对比各候选
    $pw = 'root';
    $s20 = substr($scramble, 0, 20);
    echo 'pw xor s20 + NUL : ' . bin2hex($pw[0] ^ $s20[0] . $pw[1] ^ $s20[1] . $pw[2] ^ $s20[2] . $pw[3] ^ $s20[3] . "\0") . PHP_EOL;
}

fclose($conn);
fclose($server);
