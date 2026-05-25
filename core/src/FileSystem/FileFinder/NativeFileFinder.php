<?php

declare(strict_types=1);

namespace XPHP\FileSystem\FileFinder;

use Override;
use XPHP\FileSystem\FileFinder;
use XPHP\FileSystem\FilepathArray;

readonly class NativeFileFinder implements FileFinder
{
    #[Override]
    public function find(string $path): FilepathArray
    {
        $paths = scandir($path);

        $files = array_reduce(
            $paths,
            function (array $carry, string $name) use ($path) {
                // ignore current directory
                if ('.' === $name) {
                    return $carry;
                }

                // ignore parent directory
                if ('..' === $name) {
                    return $carry;
                }

                $subPath = rtrim($path, '/') . "/$name";

                if (is_dir($subPath)) {
                    $subDirFiles = $this->find($subPath);

                    return array_merge($carry, $subDirFiles->filepaths);
                }

                if (is_file($subPath)) {
                    return array_merge($carry, [$subPath]);
                }

                return $carry;
            },
            [],
        );

        return new FilepathArray(...$files);
    }
}

