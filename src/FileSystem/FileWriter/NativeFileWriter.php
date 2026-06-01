<?php

declare(strict_types=1);

namespace XPHP\FileSystem\FileWriter;

use XPHP\FileSystem\FileWriter;

final readonly class NativeFileWriter implements FileWriter
{
    public function write(string $filepath, string $content): void
    {
        file_put_contents($filepath, $content);
    }
}
