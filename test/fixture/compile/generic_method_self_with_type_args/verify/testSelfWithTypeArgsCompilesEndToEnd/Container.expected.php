<?php

declare (strict_types=1);
namespace XPHP\Generated\App\GenericMethodSelfReturnTypeArgs\Container;

class T_6da88c34ba124c41f977db66a4fc5c1a951708d285c81bb0d47c3206f4c27ca8 implements \App\GenericMethodSelfReturnTypeArgs\Container
{
    public function __construct(public int $item)
    {
    }
    public function withItem(int $n): self
    {
        $this->item = $n;
        return $this;
    }
}
