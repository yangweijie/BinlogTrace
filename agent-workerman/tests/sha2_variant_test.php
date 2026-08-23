<?php
// caching_sha2 完整认证变体测试：尝试不同 scramble 来源 / NUL 结尾组合
$password = 'root';

function connectAndAuth(string $password, callable $pickScramble, bool $nulTerminate): string
{
    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client('tcp://127.0.0.1:3306', $errno, $errstr, 5);
    if (!$s) {
        return 'connect fail';
    }
    stream_set_timeout($s, 5);

    $readPacket = function () use ($s): ?string {
        $hdr = fread($s, 4);
        if ($hdr === false || strlen($hdr) < 4) {
            return null;
        }
        $len = ord($hdr[0]) | (ord($hdr[1]) << 8) | (ord($hdr[2]) << 16);
        $data = '';
        while (strlen($data) < $len) {
            $chunk = fread($s, $len - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    };
    $sendPacket = function (string $payload, int $seq) use ($s): void {
        $len = strlen($payload);
        $hdr = chr($len & 0xFF) . chr(($len >> 8) & 0xFF) . chr(($len >> 16) & 0xFF) . chr($seq & 0xFF);
        fwrite($s, $hdr . $payload);
    };

    $hs = $readPacket();
    if ($hs === null) {
        return 'no handshake';
    }
    $verLen = strpos($hs, "\0", 1);
    $authData1 = substr($hs, $verLen + 5, 8);
    $lowCap = unpack('v', substr($hs, $verLen + 14, 2))[1];
    $highCap = unpack('v', substr($hs, $verLen + 19, 2))[1];
    $caps = ($highCap << 16) | $lowCap;
    $authDataLen = ord($hs[$verLen + 21]);
    $authData2 = $authDataLen > 8 ? substr($hs, $verLen + 32, $authDataLen - 8) : '';
    $handshakeScramble = $authData1 . $authData2;
    $charset = ord($hs[$verLen + 16]);

    $CAP = 0x000FA20F;
    $sha1Pwd = hash('sha1', $password, true);
    $auth = $sha1Pwd ^ hash('sha1', $sha1Pwd . $handshakeScramble, true);
    $pkt = pack('V', $CAP) . pack('V', 16777215) . chr($charset) . str_repeat("\0", 23)
        . 'root' . "\0"
        . chr(strlen($auth)) . $auth
        . 'shengyibao' . "\0"
        . "mysql_native_password\0";
    $sendPacket($pkt, 1);

    $newScramble = '';
    $result = 'unknown';
    for ($step = 0; $step < 8; $step++) {
        $resp = $readPacket();
        if ($resp === null) {
            $result = 'no response';
            break;
        }
        $first = ord($resp[0]);
        if ($first === 0x00) {
            $result = 'AUTH OK';
            break;
        }
        if ($first === 0xFF) {
            $result = 'AUTH ERR: ' . substr($resp, 9);
            break;
        }
        if ($first === 0xFE) {
            $pos = 1;
            while ($pos < strlen($resp) && $resp[$pos] !== "\0") {
                $pos++;
            }
            $pos++;
            $newScramble = substr($resp, $pos);
            $h1 = hash('sha256', $password, true);
            $h2 = hash('sha256', $h1, true);
            $h3 = hash('sha256', $h2 . $newScramble, true);
            $resp2 = $h3 ^ $h1;
            $sendPacket($resp2, 3);
            continue;
        }
        if ($first === 0x01) {
            $code = ord($resp[1] ?? 0);
            if ($code === 0x03) {
                continue; // fast success → 等 OK
            }
            if ($code === 0x04) {
                $sendPacket("\x02", 5);
                continue;
            }
            // RSA 公钥
            $pem = substr($resp, 1);
            $clean = preg_replace('/-----BEGIN[^-]+-----|-----END[^-]+-----|\s+/', '', $pem);
            $pubKey = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($clean, 64, "\n") . "-----END PUBLIC KEY-----\n";
            $scramble = $pickScramble($handshakeScramble, $newScramble);
            $sLen = strlen($scramble);
            $cleartext = '';
            for ($i = 0; $i < strlen($password); $i++) {
                $cleartext .= $password[$i] ^ $scramble[$i % $sLen];
            }
            if ($nulTerminate) {
                $cleartext .= "\0";
            }
            $enc = '';
            $ok = @openssl_public_encrypt($cleartext, $enc, $pubKey, OPENSSL_PKCS1_OAEP_PADDING);
            if (!$ok) {
                $result = 'RSA FAIL (len=' . strlen($cleartext) . ')';
                break;
            }
            $sendPacket($enc, 7);
            continue;
        }
        $result = 'unexpected 0x' . dechex($first);
        break;
    }
    fclose($s);
    return $result;
}

$variants = [
    'handshake scramble + NUL' => fn($hs, $sw) => $hs,
    'authswitch scramble + NUL' => fn($hs, $sw) => $sw,
    'handshake scramble, no NUL' => fn($hs, $sw) => $hs,
    'authswitch scramble, no NUL' => fn($hs, $sw) => $sw,
    'hs first20 + NUL' => fn($hs, $sw) => substr($hs, 0, 20),
    'sw first20 + NUL' => fn($hs, $sw) => substr($sw, 0, 20),
];

foreach ($variants as $name => $fn) {
    $nul = strpos($name, 'no NUL') === false;
    echo str_pad($name, 34) . ' => ' . connectAndAuth($password, $fn, $nul) . PHP_EOL;
}
