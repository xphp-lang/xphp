<?php

declare(strict_types=1);

namespace XPHP\FileSystem\FileReader;

use XPHP\FileSystem\FileReader;
use function Amp\File\read;

final readonly class AsyncFileReader implements FileReader
{
    public function read(string $filepath): string
    {
        return read($filepath);
    }
}
