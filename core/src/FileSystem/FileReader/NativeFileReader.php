<?php

declare(strict_types=1);

namespace XPHP\FileSystem\FileReader;

use XPHP\FileSystem\FileReader;

final readonly class NativeFileReader implements FileReader
{
    public function read(string $filepath): string
    {
        return file_get_contents($filepath);
    }
}
