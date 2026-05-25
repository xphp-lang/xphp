<?php

declare(strict_types=1);

namespace XPHP\FileSystem;

interface FileFinder
{
    public function find(string $path): FilepathArray;
}
