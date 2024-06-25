<?php

declare(strict_types=1);

namespace XPHP\Transpiler;

use Exception;
use Roave\BetterReflection\BetterReflection;
use Roave\BetterReflection\Reflector\DefaultReflector;
use Roave\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\SingleFileSourceLocator;
use XPHP\FileSystem\FileReader;

final readonly class FileTranspiler
{
    public function __construct(
        private FileReader $fileReader,
        private BetterReflection $reflection,
    ) {
    }

    /**
     * @throws Exception
     */
    public function transpile(string $filepath, array $astPerFilepath): string
    {
        $singleFileReflector = new DefaultReflector(
            new AggregateSourceLocator([
                new SingleFileSourceLocator($filepath, $this->reflection->astLocator()),
                $this->reflection->sourceLocator(),
            ]),
        );

        SyntaxValidator::validate($singleFileReflector);

        $ast = $astPerFilepath[$filepath] ?? [];

        return $this->fileReader->read($filepath);
    }
}
