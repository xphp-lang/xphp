<?php

declare(strict_types=1);

namespace XPHP\Console;

use Roave\BetterReflection\BetterReflection;
use Symfony\Component\Console\Application;
use XPHP\Console\Command\TranspileCommand;
use XPHP\FileSystem\FileFinder;
use XPHP\FileSystem\FileReader;
use XPHP\Transpiler\FileBatchParser;
use XPHP\Transpiler\FileBatchTranspiler;
use XPHP\Transpiler\FileParser;
use XPHP\Transpiler\FileTranspiler;

final class ApplicationConsole extends Application
{
    public function __construct(
        FileFinder $fileFinder,
        FileReader $fileReader,
        BetterReflection $reflection,
    ) {
        parent::__construct('xphp');

        $this->add(new TranspileCommand(
            $fileFinder,
            new FileBatchTranspiler(
                new FileTranspiler(
                    $fileReader,
                    $reflection,
                ),
            ),
            new FileBatchParser(
                new FileParser(
                    $fileReader,
                    $reflection,
                ),
            ),
        ));
    }
}
