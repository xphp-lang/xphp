<?php

declare(strict_types=1);

namespace XPHP\Console\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XPHP\Diagnostics\Renderer\DiagnosticRenderer;
use XPHP\Diagnostics\Renderer\GithubRenderer;
use XPHP\Diagnostics\Renderer\JsonRenderer;
use XPHP\Diagnostics\Renderer\TextRenderer;
use XPHP\FileSystem\FileFinder;
use XPHP\Transpiler\Monomorphize\Compiler;

/**
 * `xphp check <source> [--format=text|json|github]`
 *
 * Validates the generic code without emitting any output, reporting every
 * diagnostic in one run. Exit codes: 0 = clean, 1 = at least one error-severity
 * diagnostic, 2 = operational failure (bad source dir or unknown format).
 */
#[AsCommand('check', 'Validate generic .xphp code and report diagnostics without emitting output')]
final class CheckCommand extends Command
{
    public function __construct(
        private readonly FileFinder $fileFinder,
        private readonly Compiler $compiler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Directory containing .xphp source files')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text, json, or github', 'text');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        // @infection-ignore-all CastString -- a REQUIRED argument is always a string;
        // the cast is defensive for getArgument()'s mixed return, so removing it is equivalent.
        $sourceDir = (string) $input->getArgument('source');
        if (!is_dir($sourceDir)) {
            $output->writeln("<error>Source directory not found: {$sourceDir}</error>");
            return self::INVALID;
        }

        $renderer = $this->rendererFor((string) $input->getOption('format'));
        if ($renderer === null) {
            $output->writeln('<error>Unknown format (expected: text, json, github)</error>');
            return self::INVALID;
        }

        $sources = $this->fileFinder
            ->find($sourceDir)
            ->filter(static fn (string $filepath): bool => str_ends_with($filepath, '.xphp'));

        $diagnostics = $this->compiler->check($sources);

        $output->write($renderer->render($diagnostics->all()));

        return $diagnostics->hasErrors() ? self::FAILURE : self::SUCCESS;
    }

    private function rendererFor(string $format): ?DiagnosticRenderer
    {
        return match ($format) {
            'text' => new TextRenderer(),
            'json' => new JsonRenderer(),
            'github' => new GithubRenderer(),
            default => null,
        };
    }
}
