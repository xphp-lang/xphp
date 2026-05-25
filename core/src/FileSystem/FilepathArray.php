<?php

declare(strict_types=1);

namespace XPHP\FileSystem;

readonly class FilepathArray
{
    /**
     * @var string[]
     */
    public array $filepaths;

    public function __construct(string ...$filepaths)
    {
        $this->filepaths = array_values($filepaths);
    }

    public function filter(callable $callback): self
    {
        $filepaths = array_values(array_filter($this->filepaths, $callback));

        return new self(...$filepaths);
    }
}
