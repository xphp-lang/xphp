<?php

declare(strict_types=1);

namespace XPHP\FileSystem;

interface FileWriter
{
    public function write(string $filepath, string $content): void;
}
