<?php
// probe payload: embedded PHP file (OPcache bytecode path)
namespace ProbePayload;

class Greeting
{
    public static function hi(): string
    {
        return 'hello-from-embedded';
    }
}
