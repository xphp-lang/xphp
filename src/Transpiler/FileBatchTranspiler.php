<?php

declare(strict_types=1);

namespace XPHP\Transpiler;

use Exception;

final readonly class FileBatchTranspiler
{
    public function __construct(
        private FileTranspiler $singleFileTranspiler,
    ) {
    }

    /**
     * @throws Exception
     */
    public function transpile(string $dest, array $astPerFile): void
    {
        $transpiled = array_map(
            fn (string $filepath) => $this->singleFileTranspiler->transpile($filepath, $astPerFile),
            array_keys($astPerFile),
        );
    }
}
