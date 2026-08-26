<?php

declare(strict_types=1);

final class ReproChainLeaf
{
    public string $value = 'forward';
}

final class ReproChainNode
{
    public ReproChainLeaf $leaf;

    public function __construct()
    {
        $this->leaf = new ReproChainLeaf();
    }

    public function self(): ReproChainNode
    {
        return $this;
    }
}

function main(): void
{
    $target = new ReproChainNode();
    $weak = WeakReference::create($target);
    echo $weak->get()?->self()?->leaf->value . "\n";
}
