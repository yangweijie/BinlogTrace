<?php
// 模拟浏览器 WebSocket 连接测试
$eNo = 0; $eStr = '';
$ws = @fsockopen("tcp://127.0.0.1:8082", $eNo, $eStr, 10);
if (!$ws) { die("TCP connect failed: $errstr\n"); }
echo "TCP OK\n";

$key = base64_encode(random_bytes(16));
$req = "GET / HTTP/1.1\r\n"
     . "Host: 127.0.0.1:8082\r\n"
     . "Upgrade: websocket\r\n"
     . "Connection: Upgrade\r\n"
     . "Sec-WebSocket-Key: $key\r\n"
     . "Sec-WebSocket-Version: 13\r\n"
     . "\r\n";
fwrite($ws, $req);

$resp = "";
while (strlen($resp) < 256) {
    $chunk = fread($ws, 1);
    if ($chunk === false || $chunk === "") break;
    $resp .= $chunk;
}
echo "Response:\n$resp\n";

$accept = base64_encode(sha1($key . "258EAFA5-E914-47DA-95AB-58A00390169D", true));
if (strpos($resp, $accept) !== false) {
    echo "=== WS HANDSHAKE OK ===\n";
} else {
    echo "=== WS HANDSHAKE FAIL ===\n";
    fclose($ws);
    exit(1);
}

// 发送 connect 帧
$connectMsg = json_encode([
    "v" => 2,
    "id" => "test-001",
    "type" => "connect",
    "ts" => microtime(true) * 1000,
    "payload" => [
        "host" => "127.0.0.1",
        "port" => 3306,
        "user" => "root",
        "password" => "",
        "database" => "",
        "connectTimeoutMs" => 5000,
        "serverId" => 0,
    ],
], JSON_UNESCAPED_UNICODE);
echo "Sending connect: " . strlen($connectMsg) . " bytes\n";
writeWsFrame($ws, 1, $connectMsg, true);

// 读取响应帧
stream_set_timeout($ws, 5);
$frame = readWsFrame($ws);
if ($frame) {
    $payload = $frame["payload"];
    echo "Got frame opcode=" . $frame["opcode"] . "\n";
    echo "Payload: $payload\n";
} else {
    echo "No response frame (timeout — 等待 MySQL 连接)\n";
}

fclose($ws);
echo "DONE\n";

function writeWsFrame($ws, $opcode, $payload, $masked) {
    $len = strlen($payload);
    $first = chr(0x80 | ($opcode & 0x0F));
    $frame = $first;
    if ($masked) {
        $mask = random_bytes(4);
        $payload = $mask . $payload;
    }
    if ($len <= 125) {
        $frame .= chr($len | ($masked ? 0x80 : 0));
    } elseif ($len <= 65535) {
        $frame .= chr(126 | ($masked ? 0x80 : 0)) . pack('n', $len);
    }
    $frame .= $payload;
    fwrite($ws, $frame);
}

function readWsFrame($ws) {
    $hdr = fread($ws, 2);
    if (strlen($hdr) < 2) return false;
    $first = ord($hdr[0]);
    $second = ord($hdr[1]);
    $opcode = $first & 0x0F;
    $masked = ($second & 0x80) !== 0;
    $len = $second & 0x7F;
    if ($len === 126) {
        $ext = fread($ws, 2);
        if (strlen($ext) < 2) return false;
        $len = unpack('n', $ext)[1];
    } elseif ($len === 127) {
        $ext = fread($ws, 8);
        if (strlen($ext) < 8) return false;
        $len = unpack('N', $ext)[1];
    }
    $mask = "";
    if ($masked) {
        $mask = fread($ws, 4);
        if (strlen($mask) < 4) return false;
    }
    $data = "";
    while (strlen($data) < $len) {
        $chunk = fread($ws, $len - strlen($data));
        if ($chunk === false || $chunk === "") return false;
        $data .= $chunk;
    }
    if ($masked) {
        $out = "";
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($data[$i]) ^ ord($mask[$i % 4]));
        }
        $data = $out;
    }
    return ["opcode" => $opcode, "payload" => $data];
}
