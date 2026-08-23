<?php

declare(strict_types=1);

namespace Typephp\BinlogParser\Event;

use Typephp\BinlogParser\Codec\LengthCoded;

/** 解析 binlog 事件 19 字节头部（LE 字节序） */
final class EventHeader
{
    public static function parse(string $raw): array
    {
        $len = strlen($raw);
        if ($len < 19) {
            throw new \ValueError('事件字节不足 19 字节（头部）');
        }
        $ts = LengthCoded::readUint32LE($raw, 0);
        $type = ord($raw[4]);
        $sid = LengthCoded::readUint32LE($raw, 5);
        $size = LengthCoded::readUint32LE($raw, 9);
        $pos = LengthCoded::readUint32LE($raw, 13);
        $flags = LengthCoded::readUint16LE($raw, 17);
        return [
            'timestamp' => (int)$ts['value'],
            'eventType' => $type,
            'serverId' => (int)$sid['value'],
            'eventSize' => (int)$size['value'],
            'logPos' => (int)$pos['value'],
            'flags' => (int)$flags['value'],
        ];
    }
}
