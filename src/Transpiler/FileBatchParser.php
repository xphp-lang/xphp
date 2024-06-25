<?php

declare(strict_types=1);

namespace XPHP\Transpiler;

use Exception;

final readonly class FileBatchParser
{
    public function __construct(
        private FileParser $singleFileParser,
    ) {
    }

    /**
     * @throws Exception
     */
    public function parse(string ...$sourceFiles): array
    {
        $astPerFile = array_map(
            fn (string $filepath) => $this->singleFileParser->parse($filepath),
            $sourceFiles,
        );

        return array_combine($sourceFiles, $astPerFile);
    }
}
