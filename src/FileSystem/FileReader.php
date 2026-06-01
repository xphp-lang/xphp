<?php

declare(strict_types=1);

namespace XPHP\FileSystem;

interface FileReader
{
    public function read(string $filepath): string;
}
