<?php

declare(strict_types=1);

namespace XPHP\Console;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Symfony\Component\Console\Application;
use XPHP\Console\Command\CompileCommand;
use XPHP\FileSystem\FileFinder;
use XPHP\FileSystem\FileReader;
use XPHP\FileSystem\FileWriter;
use XPHP\Transpiler\Monomorphize\Compiler;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\SpecializedClassGenerator;
use XPHP\Transpiler\Monomorphize\Specializer;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

final class ApplicationConsole extends Application
{
    public function __construct(
        FileFinder $fileFinder,
        FileReader $fileReader,
        FileWriter $fileWriter,
        int $hashLength = Registry::DEFAULT_HASH_HEX_LENGTH,
    ) {
        parent::__construct('xphp');

        // Parse against the host PHP version: xphp's supported runtime is
        // ^8.4 (see composer.json), so on 8.4 the transpiler accepts 8.4
        // syntax and on an 8.5 host it transparently accepts 8.5 syntax such
        // as the pipe operator `|>`. Newer-syntax behaviour is locked by
        // tests tagged `@group php85`, which run only under an 8.5 runtime.
        $phpParser = (new ParserFactory())->createForHostVersion();
        $printer = new StandardPrinter();

        $this->addCommand(new CompileCommand(
            $fileFinder,
            new Compiler(
                $fileReader,
                $fileWriter,
                new XphpSourceParser($phpParser),
                new Specializer(),
                new SpecializedClassGenerator($printer, $fileWriter),
                $printer,
                $hashLength,
            ),
        ));
    }
}
