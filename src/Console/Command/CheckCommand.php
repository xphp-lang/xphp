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
use XPHP\StaticAnalysis\StaticAnalysisGate;
use XPHP\Transpiler\Monomorphize\Compiler;

/**
 * `xphp check <source> [--format=text|json|github] [--no-phpstan]
 *  [--phpstan-bin=PATH] [--phpstan-config=PATH]`
 *
 * Validates the generic code without emitting any output, reporting every
 * diagnostic in one run. When the generic checks pass, it then runs the
 * consumer's PHPStan over the compiled (concrete) output and merges those
 * findings into the same report — one gate, one exit code.
 *
 * Exit codes: 0 = clean, 1 = at least one error-severity diagnostic, 2 =
 * operational failure (bad source dir or unknown format).
 */
#[AsCommand('check', 'Validate generic .xphp code and report diagnostics without emitting output')]
final class CheckCommand extends Command
{
    public function __construct(
        private readonly FileFinder $fileFinder,
        private readonly Compiler $compiler,
        private readonly StaticAnalysisGate $staticAnalysisGate,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Directory containing .xphp source files')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text, json, or github', 'text')
            ->addOption('no-phpstan', null, InputOption::VALUE_NONE, 'Skip the PHPStan pass over the compiled output')
            ->addOption('phpstan-bin', null, InputOption::VALUE_REQUIRED, 'Path to the PHPStan binary (default: vendor/bin/phpstan, then $PATH)')
            ->addOption('phpstan-config', null, InputOption::VALUE_REQUIRED, 'Path to the PHPStan config (default: auto-detect phpstan.neon[.dist])');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        // getArgument()/getOption() are typed `mixed`; these are scalar inputs (a required
        // argument and an option with a string default), so they are always strings — narrow
        // rather than blind-cast (PHPStan level 9 rejects casting mixed).
        $sourceArg = $input->getArgument('source');
        $sourceDir = is_string($sourceArg) ? $sourceArg : '';
        if (!is_dir($sourceDir)) {
            $output->writeln("<error>Source directory not found: {$sourceDir}</error>");
            return self::INVALID;
        }

        $formatOption = $input->getOption('format');
        $renderer = $this->rendererFor(is_string($formatOption) ? $formatOption : '');
        if ($renderer === null) {
            $output->writeln('<error>Unknown format (expected: text, json, github)</error>');
            return self::INVALID;
        }

        $sources = $this->fileFinder
            ->find($sourceDir)
            ->filter(static fn (string $filepath): bool => str_ends_with($filepath, '.xphp'));

        $diagnostics = $this->compiler->check($sources);

        // Only layer PHPStan on when the generic checks pass: invalid generics can't be
        // compiled to the concrete output PHPStan needs, and reporting both at once would
        // just be noise on top of the real (generic) errors.
        if (!$diagnostics->hasErrors() && $input->getOption('no-phpstan') !== true) {
            $binOption = $input->getOption('phpstan-bin');
            $configOption = $input->getOption('phpstan-config');
            $findings = $this->staticAnalysisGate->analyze(
                $sources,
                $sourceDir,
                getcwd() ?: '.',
                is_string($binOption) ? $binOption : null,
                is_string($configOption) ? $configOption : null,
            );
            foreach ($findings as $finding) {
                $diagnostics->add($finding);
            }
        }

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
