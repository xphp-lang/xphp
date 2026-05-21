<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use RuntimeException;
use XPHP\FileSystem\FileReader;
use XPHP\FileSystem\FileWriter;
use XPHP\FileSystem\FilepathArray;

/**
 * Orchestrates the monomorphization pipeline.
 *
 * Phases:
 *  1. Parse + initial collect — read each .xphp file, parse into an AST with generic metadata,
 *     and collect every concrete top-level instantiation (and its transitive nested instantiations).
 *  2. Specialize loop — for each instantiation in the registry, run the Specializer to produce a
 *     concrete ClassLike AST (Class_/Interface_/Trait_). Walk that AST with the RegistryCollector
 *     to discover any *new* generic instantiations that surface only after substitution
 *     (e.g. `class Wrapper<T> { public Box<T> $b; }` produces a `Box<Plastic>` instantiation when
 *     specialized as `Wrapper<Plastic>`). Loop until no new entries appear or the depth cap is reached.
 *  3. Emit specialized classes — rewrite each specialized AST (replace remaining Name nodes carrying
 *     genericArgs with FullyQualified XPHP\Generated references) and write to .xphp-cache/Generated/.
 *  4. Emit rewritten user code — rewrite each original source AST (strip generic class defs,
 *     rewrite generic Name references), pretty-print, and write to the target directory.
 *  5. Persist registry — write .xphp-cache/registry.json.
 */
final readonly class Compiler
{
    public const MAX_SPECIALIZATION_DEPTH = 16;

    public function __construct(
        private FileReader $fileReader,
        private FileWriter $fileWriter,
        private XphpSourceParser $sourceParser,
        private Specializer $specializer,
        private SpecializedClassGenerator $specializedClassGenerator,
        private StandardPrinter $printer,
        private int $hashLength = Registry::DEFAULT_HASH_HEX_LENGTH,
    ) {
    }

    public function compile(
        FilepathArray $sources,
        string $sourceDir,
        string $targetDir,
        string $cacheDir,
    ): CompileResult {
        // Phase 0: parse every source up front. The TypeHierarchy (used to validate generic
        // bounds at recordInstantiation time) needs to see every class/interface/trait
        // declaration *before* any instantiation is recorded, so parsing has to finish first.
        $astPerFile = [];
        foreach ($sources->filepaths as $filepath) {
            $content = $this->fileReader->read($filepath);
            $astPerFile[$filepath] = $this->sourceParser->parse($content);
        }

        $hierarchy = TypeHierarchy::fromAstPerFile($astPerFile);
        $registry = new Registry($this->hashLength, $hierarchy);
        $collector = new RegistryCollector($registry);

        // Phase 1: collect (definitions + instantiations) using the now-populated hierarchy.
        foreach ($astPerFile as $filepath => $ast) {
            $collector->collect($ast, $filepath);
        }

        // Phase 2: fixed-point specialization loop.
        /** @var array<string, \PhpParser\Node\Stmt\ClassLike> $specializedAsts keyed by generated FQCN */
        $specializedAsts = [];
        $depth = 0;
        while (true) {
            $countBefore = count($registry->instantiations());
            $newlyProcessed = false;

            foreach ($registry->instantiations() as $generatedFqn => $instantiation) {
                if (isset($specializedAsts[$generatedFqn])) {
                    continue;
                }
                $newlyProcessed = true;

                $definition = $registry->definition($instantiation->templateFqn);
                if ($definition === null) {
                    throw new RuntimeException(sprintf(
                        'Generic template "%s" was instantiated but never defined (generated as: %s).',
                        $instantiation->templateFqn,
                        $generatedFqn,
                    ));
                }

                $substitution = array_combine($definition->typeParamNames(), $instantiation->concreteTypes);
                $specialized = $this->specializer->specialize(
                    $definition->templateAst,
                    $substitution,
                );

                $specializedAsts[$generatedFqn] = $specialized;
                $collector->collect([$specialized], "<specialized:{$generatedFqn}>");
            }

            $countAfter = count($registry->instantiations());
            if (!$newlyProcessed) {
                break;
            }

            if ($countAfter === $countBefore) {
                continue;
            }

            $depth++;
            if ($depth > self::MAX_SPECIALIZATION_DEPTH) {
                throw new RuntimeException(sprintf(
                    'Nested generic specialization exceeded depth %d. Latest registry: %s',
                    self::MAX_SPECIALIZATION_DEPTH,
                    implode(', ', array_keys($registry->instantiations())),
                ));
            }
        }

        // Phase 3: rewrite + emit specialized classes.
        $rewriter = new CallSiteRewriter($registry);
        foreach ($specializedAsts as $generatedFqn => $classAst) {
            $rewritten = $rewriter->rewrite([$classAst]);
            $this->specializedClassGenerator->emit($rewritten[0], $generatedFqn, $cacheDir);
        }

        // Phase 4: rewrite + emit user source files.
        foreach ($astPerFile as $filepath => $ast) {
            $rewrittenAst = $rewriter->rewrite($ast);
            $code = $this->printer->prettyPrintFile($rewrittenAst);

            $relPath = self::relativePath($sourceDir, $filepath);
            $targetPath = rtrim($targetDir, '/') . '/' . preg_replace('/\.xphp$/', '.php', $relPath);

            $targetSubdir = dirname($targetPath);
            if (!is_dir($targetSubdir)) {
                mkdir($targetSubdir, 0o755, true);
            }

            $this->fileWriter->write($targetPath, $code);
        }

        // Phase 5: persist registry.
        $registryPath = rtrim($cacheDir, '/') . '/registry.json';
        if (!is_dir(dirname($registryPath))) {
            mkdir(dirname($registryPath), 0o755, true);
        }
        $this->fileWriter->write(
            $registryPath,
            json_encode($registry->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return new CompileResult(
            sourceCount: count($sources->filepaths),
            generatedCount: count($specializedAsts),
            registry: $registry,
        );
    }

    private static function relativePath(string $base, string $filepath): string
    {
        $base = rtrim($base, '/') . '/';
        if (str_starts_with($filepath, $base)) {
            return substr($filepath, strlen($base));
        }
        return basename($filepath);
    }
}

