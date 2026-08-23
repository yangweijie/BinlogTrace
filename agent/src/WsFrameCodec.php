<?php

declare(strict_types=1);

/**
 * WsFrameCodec — RFC 6455 WebSocket 帧编解码
 * 浏览器侧帧带 MASK（强制）；代理侧帧不带 MASK
 * 所有二进制通过 pack/unpack 处理，避免跨边界二进制
 */
final class WsFrameCodec
{
    public const int OPCODE_CONT = 0x00;
    public const int OPCODE_TEXT = 0x01;
    public const int OPCODE_BINARY = 0x02;
    public const int OPCODE_CLOSE = 0x88;
    public const int OPCODE_PING = 0x89;
    public const int OPCODE_PONG = 0x8A;

    /**
     * 从 stream 读取一帧（阻塞）
     * 返回 array { opcode:int, payload:string, isControl:bool } 或 false（EOF）
     */
    public static function readFrame($stream)
    {
        echo '[agent] readFrame: reading header (2 bytes)' . PHP_EOL;
        $header = self::readExact($stream, 2);
        if ($header === false) {
            echo '[agent] readFrame: header read failed' . PHP_EOL;
            return false;
        }
        if (strlen($header) < 2) {
            echo '[agent] readFrame: header too short' . PHP_EOL;
            return false;
        }

        $firstByte = ord($header[0]);
        $secondByte = ord($header[1]);
        $opcode = $firstByte & 0x0F;
        $masked = ($secondByte & 0x80) !== 0;
        $payloadLen = $secondByte & 0x7F;
        echo '[agent] readFrame: opcode=' . $opcode . ' masked=' . (int)$masked . ' payloadLen=' . $payloadLen . PHP_EOL;

        if ($payloadLen === 126) {
            $ext = self::readExact($stream, 2);
            if ($ext === false || strlen($ext) < 2) {
                return false;
            }
            $payloadLen = self::readUint16BE($ext);
        } elseif ($payloadLen === 127) {
            $ext = self::readExact($stream, 8);
            if ($ext === false || strlen($ext) < 8) {
                return false;
            }
            $payloadLen = self::readUint64BE($ext);
        }

        if ($payloadLen < 0 || $payloadLen > AgentConstants::MAX_FRAME_SIZE) {
            return false;
        }

        $maskKey = '';
        if ($masked) {
            $maskKey = self::readExact($stream, 4);
            if ($maskKey === false || strlen($maskKey) < 4) {
                return false;
            }
        }

        $payload = '';
        if ($payloadLen > 0) {
            $payload = self::readExact($stream, (int)$payloadLen);
            if ($payload === false) {
                return false;
            }
        }

        if ($masked && $payloadLen > 0) {
            $payload = self::unmask($payload, $maskKey);
        }

        $isControl = ($opcode & 0x08) !== 0;
        return ['opcode' => $opcode, 'payload' => $payload, 'isControl' => $isControl];
    }

    /**
     * 写入一帧到 stream（代理侧不掩码）
     */
    public static function writeFrame($stream, int $opcode, string $payload): bool
    {
        $len = strlen($payload);
        $firstByte = chr(0x80 | ($opcode & 0x0F));
        if ($len <= 125) {
            $header = $firstByte . chr($len);
            $frame = $header . $payload;
        } elseif ($len <= 65535) {
            $header = $firstByte . chr(126) . pack('n', $len);
            $frame = $header . $payload;
        } else {
            $header = $firstByte . chr(127) . pack('N', 0) . pack('N', 0) . pack('n', 0) . pack('n', 0);
            $frame = $header . $payload;
        }
        return self::writeAll($stream, $frame) === strlen($frame);
    }

    /**
     * 写入 close 帧（含状态码）
     */
    public static function writeClose($stream, int $code = 1000, string $reason = ''): bool
    {
        $body = pack('n', $code) . $reason;
        return self::writeFrame($stream, self::OPCODE_CLOSE, $body);
    }

    /**
     * 写入 ping 帧
     */
    public static function writePing($stream, string $payload = ''): bool
    {
        return self::writeFrame($stream, self::OPCODE_PING, $payload);
    }

    /**
     * 写入 pong 帧
     */
    public static function writePong($stream, string $payload = ''): bool
    {
        return self::writeFrame($stream, self::OPCODE_PONG, $payload);
    }

    /** 执行 WebSocket handshake 并返回 true/false */
    public static function doHandshake($stream): bool
    {
        echo '[agent] doHandshake: reading HTTP request' . PHP_EOL;
        $request = self::readHttpRequest($stream);
        if ($request === false) {
            echo '[agent] doHandshake: readHttpRequest returned false' . PHP_EOL;
            return false;
        }
        echo '[agent] doHandshake: request read OK, length=' . strlen($request) . PHP_EOL;

        $key = self::getHeader($request, 'Sec-WebSocket-Key');
        if ($key === false) {
            echo '[agent] doHandshake: Sec-WebSocket-Key header NOT FOUND' . PHP_EOL;
            return false;
        }
        echo '[agent] doHandshake: Sec-WebSocket-Key found' . PHP_EOL;

        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95AB-58A00390169D', true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: " . $accept . "\r\n"
            . "\r\n";
        $wrote = self::writeAll($stream, $response);
        echo '[agent] doHandshake: wrote ' . $wrote . '/' . strlen($response) . ' bytes' . PHP_EOL;
        return $wrote === strlen($response);
    }

    private static function readHttpRequest($stream): string|false
    {
        $headers = '';
        while (true) {
            $line = self::readLine($stream);
            if ($line === false) {
                return false;
            }
            $headers .= $line . "\r\n";
            if ($line === '') {
                break;
            }
        }
        return $headers;
    }

    private static function readLine($stream): string|false
    {
        $buf = '';
        while (true) {
            $c = fread($stream, 1);
            if ($c === false || $c === '') {
                return false;
            }
            $buf .= $c;
            if ($c === "\n") {
                return rtrim($buf, "\r\n");
            }
        }
    }

    private static function readExact($stream, int $n): string|false
    {
        if ($n <= 0) {
            return '';
        }
        $data = '';
        $remain = $n;
        $maxIter = 100;
        $iter = 0;
        while ($remain > 0 && $iter < $maxIter) {
            $chunk = @stream_socket_recvfrom($stream, $remain);
            if ($chunk === false || $chunk === '') {
                $chunk = fread($stream, $remain);
            }
            $got = $chunk === false || $chunk === '' ? 0 : strlen($chunk);
            if ($got === 0) {
                echo '[agent] readExact: read 0 bytes (remain=' . $remain . ', iter=' . $iter . ')' . PHP_EOL;
                return false;
            }
            $data .= $chunk;
            $remain -= $got;
            $iter++;
        }
        echo '[agent] readExact: read ' . $n . ' bytes in ' . $iter . ' iters' . PHP_EOL;
        return $data;
    }

    private static function writeAll($stream, string $data): int
    {
        $total = 0;
        $buf = $data;
        while (strlen($buf) > 0) {
            $wrote = fwrite($stream, $buf);
            if ($wrote === false || $wrote === 0) {
                return $total;
            }
            $total += $wrote;
            $buf = substr($buf, $wrote);
        }
        return $total;
    }

    private static function unmask(string $payload, string $maskKey): string
    {
        $maskLen = 4;
        $payloadLen = strlen($payload);
        $result = '';
        for ($i = 0; $i < $payloadLen; $i++) {
            $result .= chr(ord($payload[$i]) ^ ord($maskKey[$i % $maskLen]));
        }
        return $result;
    }

    private static function readUint16BE(string $bytes): int
    {
        $arr = unpack('n', $bytes);
        return (int)($arr[1] ?? 0);
    }

    private static function readUint64BE(string $bytes): int
    {
        $hi = self::readUint32BE(substr($bytes, 0, 4));
        $lo = self::readUint32BE(substr($bytes, 4, 4));
        return $hi * 4294967296 + $lo;
    }

    private static function readUint32BE(string $bytes): int
    {
        $arr = unpack('N', $bytes);
        return (int)($arr[1] ?? 0);
    }

    private static function getHeader(string $headers, string $name): string|false
    {
        $needle = strtolower($name);
        $parts = explode("\r\n", $headers);
        for ($i = 0; $i < count($parts); $i++) {
            $line = $parts[$i];
            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }
            if (strtolower(substr($line, 0, $colonPos)) === $needle) {
                return trim(substr($line, $colonPos + 1));
            }
        }
        return false;
    }
}