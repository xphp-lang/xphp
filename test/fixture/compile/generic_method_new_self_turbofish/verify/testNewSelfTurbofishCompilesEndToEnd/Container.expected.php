<?php

declare (strict_types=1);
namespace XPHP\Generated\App\GenericMethodNewSelfTurbofish\Container;

class T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8 implements \App\GenericMethodNewSelfTurbofish\Container
{
    public function __construct(public int $item)
    {
    }
    public function with(int $n): self
    {
        return new self($n);
    }
}
