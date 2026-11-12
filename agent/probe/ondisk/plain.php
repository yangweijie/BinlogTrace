<?php
// probe: plain PHP on disk (NOT embedded) — required at runtime from embed SAPI
namespace ProbeDisk;

class Plain
{
    public static function hi(): string
    {
        return 'plain-from-disk';
    }
}

$probeDiskGlobal = 'global-scope-ok';
