<?php

declare(strict_types=1);

namespace XPHP\Console;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Roave\BetterReflection\BetterReflection;
use Symfony\Component\Console\Application;
use XPHP\Console\Command\CompileCommand;
use XPHP\Console\Command\TranspileCommand;
use XPHP\FileSystem\FileFinder;
use XPHP\FileSystem\FileReader;
use XPHP\FileSystem\FileWriter;
use XPHP\Transpiler\FileBatchParser;
use XPHP\Transpiler\FileBatchTranspiler;
use XPHP\Transpiler\FileParser;
use XPHP\Transpiler\FileTranspiler;
use XPHP\Transpiler\Monomorphize\Compiler;
use XPHP\Transpiler\Monomorphize\SpecializedClassGenerator;
use XPHP\Transpiler\Monomorphize\Specializer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class ApplicationConsole extends Application
{
    public function __construct(
        FileFinder $fileFinder,
        FileReader $fileReader,
        FileWriter $fileWriter,
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

        $phpParser = (new ParserFactory())->createForHostVersion();
        $printer = new StandardPrinter();

        $this->add(new CompileCommand(
            $fileFinder,
            new Compiler(
                $fileReader,
                $fileWriter,
                new XphpSourceParser($phpParser),
                new Specializer(),
                new SpecializedClassGenerator($printer, $fileWriter),
                $printer,
            ),
        ));
    }
}
