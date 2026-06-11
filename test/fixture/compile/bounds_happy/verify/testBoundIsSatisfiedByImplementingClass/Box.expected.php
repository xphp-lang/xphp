<?php

declare (strict_types=1);
namespace XPHP\Generated\App\BoundsHappy\Containers\Box;

class T_9abf23a93e6d1092918ca17376063e0fee0c3cc1538c9ef2c5bd3f1bff651936 implements \App\BoundsHappy\Containers\Box
{
    public function __construct(public \App\BoundsHappy\Models\Tag $item)
    {
    }
    public function describe(): string
    {
        return (string) $this->item;
    }
}
