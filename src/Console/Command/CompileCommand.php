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
use XPHP\Config\SourceResolver;
use XPHP\Transpiler\Monomorphize\Compiler;

/**
 * `xphp compile [<source> [<target> [<cache>]]] [--config=PATH] [--target=DIR] [--cache=DIR]`
 *
 * Single-dir form (back-compatible): `xphp compile src dist cache`. Manifest form: omit the source
 * and supply `--config <xphp.json>` (or run where an `xphp.json` is auto-detected) to compile a
 * package together with its declared sources and transitively-included packages in one pass.
 * Output dirs resolve as: `--target`/`--cache` option > manifest value > positional arg > default.
 */
#[AsCommand('compile')]
final class CompileCommand extends Command
{
    public function __construct(
        private readonly SourceResolver $sourceResolver,
        private readonly Compiler $compiler,
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
            ->addOption('cache', null, InputOption::VALUE_REQUIRED, 'Cache dir (overrides the manifest and positional arg)');
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
        // which manifest mode lacks), so each mode picks its own fallback — keeping every step
        // reachable rather than chaining a dead manifest-vs-positional link.
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
        // @infection-ignore-all -- rootByFile covers every file, so the scalar base is never read.
        $base = $sourceArg ?? '';
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
