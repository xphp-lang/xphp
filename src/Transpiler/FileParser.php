<?php
declare(strict_types=1);

namespace XPHP\Transpiler;

use PhpParser\Node\Stmt;
use Roave\BetterReflection\BetterReflection;
use XPHP\FileSystem\FileReader;

final readonly class FileParser
{
    public function __construct(
        private FileReader $fileReader,
        private BetterReflection $reflection,
    ) {
    }

    /**
     * @param string $filepath
     * @return Stmt[]
     */
    public function parse(string $filepath): array
    {
        $fileContent = $this->fileReader->read($filepath);
        $ast = $this->reflection->phpParser()->parse($fileContent);

        return $ast ?? [];
    }
}
