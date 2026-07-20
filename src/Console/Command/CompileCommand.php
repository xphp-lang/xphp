<?php

declare(strict_types=1);

namespace XPHP\Console\Command;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XPHP\Config\ResolvedSources;
use XPHP\Config\SourceResolver;
use XPHP\Diagnostics\Renderer\RendererFactory;
use XPHP\StaticAnalysis\Gate;
use XPHP\Transpiler\Monomorphize\Compiler;

/**
 * `xphp compile [<source> [<target> [<cache>]]] [--config=PATH] [--target=DIR] [--cache=DIR]
 *  [--no-check] [--no-phpstan] [--phpstan-bin=PATH] [--phpstan-config=PATH] [--format=…]`
 *
 * Single-dir form (back-compatible): `xphp compile src dist cache`. Manifest form: omit the source
 * and supply `--config <xphp.json>` (or run where an `xphp.json` is auto-detected).
 * Output dirs resolve as: `--target`/`--cache` option > manifest value > positional arg > default.
 *
 * Safe by default: compile runs the same validation gate as `xphp check` (generic validators + PHPStan over
 * the compiled output) FIRST, and emits nothing if the gate reports an error — so a typo'd type or an
 * undeclared class fails the build at compile time instead of slipping through to runtime. `--no-check`
 * skips the gate (fast, today's behavior) for a trusted iteration build.
 */
#[AsCommand('compile')]
final class CompileCommand extends Command
{
    public function __construct(
        private readonly SourceResolver $sourceResolver,
        private readonly Compiler $compiler,
        private readonly Gate $gate,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::OPTIONAL, 'Directory containing .xphp source files (omit when using --config or an auto-detected xphp.json)')
            ->addArgument('target', InputArgument::OPTIONAL, 'Directory to emit rewritten .php files (default: dist)')
            ->addArgument('cache', InputArgument::OPTIONAL, 'Directory for generated specialized classes (default: .xphp-cache)')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to an xphp.json manifest (or a dir containing one)')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Emit dir (overrides the manifest and positional arg)')
            ->addOption('cache', null, InputOption::VALUE_REQUIRED, 'Cache dir (overrides the manifest and positional arg)')
            ->addOption('no-check', null, InputOption::VALUE_NONE, 'Skip the validation gate and compile directly (faster; no typo/type safety net)')
            ->addOption('no-phpstan', null, InputOption::VALUE_NONE, 'Run only the generic validators in the gate, skipping the PHPStan pass')
            ->addOption('phpstan-bin', null, InputOption::VALUE_REQUIRED, 'Path to the PHPStan binary (default: vendor/bin/phpstan, then $PATH)')
            ->addOption('phpstan-config', null, InputOption::VALUE_REQUIRED, 'Path to the PHPStan config (default: auto-detect phpstan.neon[.dist])')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Diagnostic output format: text, json, or github', 'text');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $sourceArg = self::stringOrNull($input->getArgument('source'));
        $configOpt = self::stringOrNull($input->getOption('config'));

        // @infection-ignore-all -- getcwd() is effectively always a string under any run; the
        // `?: '.'` fallback resolves identically to the live cwd for auto-detection.
        $cwd = getcwd() ?: '.';
        try {
            $resolved = $this->sourceResolver->resolve($sourceArg, $configOpt, $cwd);
        } catch (RuntimeException $e) {
            // @infection-ignore-all -- the <error> banner is decoration; the message content is
            // what's asserted, so reordering/removing the wrapping tags is behaviourally immaterial.
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }

        // Output dirs: option > (manifest value | positional arg) > default. The manifest value and
        // the positional arg never coexist (positional target/cache require a positional source,
        // which manifest mode lacks), so each mode picks its own fallback.
        $targetOpt = self::stringOrNull($input->getOption('target'));
        $cacheOpt = self::stringOrNull($input->getOption('cache'));
        if ($sourceArg !== null) {
            $target = $targetOpt ?? self::stringOrNull($input->getArgument('target')) ?? 'dist';
            $cache = $cacheOpt ?? self::stringOrNull($input->getArgument('cache')) ?? '.xphp-cache';
        } else {
            $target = $targetOpt ?? $resolved->target ?? 'dist';
            $cache = $cacheOpt ?? $resolved->cache ?? '.xphp-cache';
        }

        // `$resolved->rootByFile` is authoritative for emit paths; the scalar base is only a
        // fallback for any unmapped file (none here), so the source arg (or empty) suffices.
        // @infection-ignore-all -- rootByFile maps EVERY file (single-dir mirrors $dir==$sourceArg;
        // manifest populates each file), so $base is a never-consulted fallback: `$sourceArg ?? ''`
        // and `''` emit to identical paths. Same equivalence annotated at SourceResolver::fromDirectory.
        $base = $sourceArg ?? '';

        // --no-check: compile directly (today's behavior), trusting that the gate has been run separately.
        if ($input->getOption('no-check') === true) {
            return $this->emit($resolved, $base, $target, $cache, $output);
        }

        $formatOption = $input->getOption('format');
        $renderer = RendererFactory::for(is_string($formatOption) ? $formatOption : '');
        if ($renderer === null) {
            $output->writeln('<error>Unknown format (expected: text, json, github)</error>');
            return self::INVALID;
        }

        $runPhpStan = $input->getOption('no-phpstan') !== true;
        $phpstanBin = self::stringOrNull($input->getOption('phpstan-bin'));
        $phpstanConfigOpt = self::stringOrNull($input->getOption('phpstan-config'));

        $diagnostics = $this->gate->run(
            $resolved->files,
            $base,
            $cwd,
            $runPhpStan,
            $phpstanBin,
            $phpstanConfigOpt,
            $resolved->rootByFile,
        );

        if ($diagnostics->hasErrors()) {
            // Emit nothing: the gate runs before any output is written, so there is nothing to roll back.
            $output->write($renderer->render($diagnostics->all()));
            return self::FAILURE;
        }

        // Surface non-error diagnostics (e.g. PHPStan unavailable) but proceed to compile.
        if ($diagnostics->all() !== []) {
            $output->write($renderer->render($diagnostics->all()));
        }

        return $this->emit($resolved, $base, $target, $cache, $output);
    }

    private function emit(
        ResolvedSources $resolved,
        string $base,
        string $target,
        string $cache,
        OutputInterface $output,
    ): int {
        $result = $this->compiler->compile(
            $resolved->files,
            $base,
            $target,
            $cache,
            $resolved->rootByFile,
        );

        $output->writeln(sprintf(
            'Compiled %d source file(s); generated %d specialized class(es).',
            $result->sourceCount,
            $result->generatedCount,
        ));

        return self::SUCCESS;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
