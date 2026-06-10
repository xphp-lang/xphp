<?php

declare(strict_types=1);

namespace XPHP\FileSystem\FileReader;

use RuntimeException;
use XPHP\FileSystem\FileReader;

final readonly class NativeFileReader implements FileReader
{
    public function read(string $filepath): string
    {
        $content = file_get_contents($filepath);
        if ($content === false) {
            throw new RuntimeException("could not read file: {$filepath}");
        }
        return $content;
    }
}
