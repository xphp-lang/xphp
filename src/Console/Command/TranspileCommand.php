<?php

declare(strict_types=1);

namespace XPHP\Console\Command;

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use XPHP\FileSystem\FileFinder;
use XPHP\Transpiler\FileBatchParser;
use XPHP\Transpiler\FileBatchTranspiler;

#[AsCommand('transpile')]
final class TranspileCommand extends Command
{
    public function __construct(
        private readonly FileFinder $fileFinder,
        private readonly FileBatchTranspiler $fileBatchTranspiler,
        private readonly FileBatchParser $fileBatchParser,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->addArgument('source', InputArgument::REQUIRED)
            ->addArgument('target', InputArgument::REQUIRED);
    }

    /**
     * @throws Exception
     */
    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $sourceFiles = $this->fileFinder
            ->find($input->getArgument('source'))
            ->filter(fn (string $filepath) => str_ends_with($filepath, '.php'));

        $astPerFile = $this->fileBatchParser->parse(
            ...$sourceFiles->filepaths,
        );

        $this->fileBatchTranspiler->transpile($input->getArgument('target'), $astPerFile);

        return self::SUCCESS;
    }
}
