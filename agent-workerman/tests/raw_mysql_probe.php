<?php
// 直接连 127.0.0.1:3306，读服务端握手包原始字节
$errno = 0;
$errstr = '';
$s = @stream_socket_client('tcp://127.0.0.1:3306', $errno, $errstr, 5);
if (!$s) {
    echo "connect fail: {$errno} {$errstr}\n";
    exit(1);
}
stream_set_timeout($s, 5);
$data = fread($s, 256);
echo "got " . strlen($data) . " bytes\n";
if ($data !== false && $data !== '') {
    echo 'first byte = 0x' . strtoupper(dechex(ord($data[0]))) . ' (decimal ' . ord($data[0]) . ')' . PHP_EOL;
    echo 'hex: ' . bin2hex($data) . PHP_EOL;
    // 尝试解握手包头
    $payloadLen = ord($data[0]) | (ord($data[1]) << 8) | (ord($data[2]) << 16);
    echo "header payloadLen = {$payloadLen}\n";
}
fclose($s);
