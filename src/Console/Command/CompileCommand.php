<?php

declare(strict_types=1);

namespace XPHP\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use XPHP\FileSystem\FileFinder;
use XPHP\Transpiler\Monomorphize\Compiler;

#[AsCommand('compile')]
final class CompileCommand extends Command
{
    public function __construct(
        private readonly FileFinder $fileFinder,
        private readonly Compiler $compiler,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Directory containing .xphp source files')
            ->addArgument('target', InputArgument::OPTIONAL, 'Directory to emit rewritten .php files', 'dist')
            ->addArgument('cache', InputArgument::OPTIONAL, 'Directory for generated specialized classes', '.xphp-cache');
    }

    public function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        // getArgument() is typed `mixed`; these are scalar args (a required one and two with
        // string defaults), so they are always strings — narrow rather than blind-cast.
        $sourceArg = $input->getArgument('source');
        $targetArg = $input->getArgument('target');
        $cacheArg = $input->getArgument('cache');
        $sourceDir = is_string($sourceArg) ? $sourceArg : '';
        $targetDir = is_string($targetArg) ? $targetArg : 'dist';
        $cacheDir = is_string($cacheArg) ? $cacheArg : '.xphp-cache';

        if (!is_dir($sourceDir)) {
            $output->writeln("<error>Source directory not found: {$sourceDir}</error>");
            return self::FAILURE;
        }

        $sources = $this->fileFinder
            ->find($sourceDir)
            ->filter(static fn (string $filepath): bool => str_ends_with($filepath, '.xphp'));

        $result = $this->compiler->compile($sources, $sourceDir, $targetDir, $cacheDir);

        $output->writeln(sprintf(
            'Compiled %d source file(s); generated %d specialized class(es).',
            $result->sourceCount,
            $result->generatedCount,
        ));

        return self::SUCCESS;
    }
}
