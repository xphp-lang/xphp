<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Error as PhpParserError;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use RuntimeException;
use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\Diagnostics\Severity;
use XPHP\Diagnostics\SourceLocation;
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

    /** Diagnostic code for a file that failed to parse during `xphp check`. */
    public const CODE_PARSE_ERROR = 'xphp.parse_error';

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

    /**
     * @param ?array<string,string> $rootByFile Optional map of absolute source filepath → the
     *   source root it was found under, used to compute each emitted file's relative (PSR-4) path.
     *   When null (the single-source-dir form), every file is relative to $sourceDir, exactly as
     *   before. When supplied (manifest / multi-root form), each file is relative to its own root,
     *   so a second root's files don't flatten; $sourceDir is the fallback for any unmapped file.
     */
    public function compile(
        FilepathArray $sources,
        string $sourceDir,
        string $targetDir,
        string $cacheDir,
        ?array $rootByFile = null,
    ): CompileResult {
        // Phase 0: parse every source up front. The TypeHierarchy (used to validate generic
        // bounds at recordInstantiation time) needs to see every class/interface/trait
        // declaration *before* any instantiation is recorded, so parsing has to finish first.
        $astPerFile = $this->parseAll($sources);

        $hierarchy = TypeHierarchy::fromAstPerFile($astPerFile);
        $registry = new Registry($this->hashLength, $hierarchy);
        $collector = new RegistryCollector($registry);

        // Phase 1a: method/function-level specialization runs FIRST, against the raw user-file
        // ASTs. The substitution visitor recursively rewrites ATTR_GENERIC_ARGS, so a body like
        // `function wrap<T>(T): Box<T> { return new Box<T>(...); }` produces a specialized
        // `wrap_T_<hash>(int): Box<int> { return new Box<int>(...); }` AFTER substitution. By
        // running this before the class collector + fixed-point loop, the newly-introduced
        // concrete `Box<int>` reference gets collected and specialized through the usual
        // class-level path. The hierarchy is passed through so method/function-level
        // `T: Bound` is validated at compile time too (same shape as the class-level path).
        // Reject a stray/undeclared type parameter in a generic method/function/closure
        // signature BEFORE specialization runs (process() strips the templates from the
        // AST in compile-mode, so this must precede it).
        UndeclaredTypeParameterValidator::assertMethodLevel($astPerFile, $hierarchy);

        $methodCompiler = new GenericMethodCompiler($this->hashLength, $hierarchy);
        $methodCompiler->process($astPerFile);

        // Phase 1b.i: collect class definitions across every source file. Splitting
        // definitions ahead of instantiations gives bare-`new Foo;` synthesis (added
        // in 1b.ii) a complete template registry so it can recognize Foo as an
        // all-defaulted template regardless of the file-walk order.
        foreach ($astPerFile as $filepath => $ast) {
            $collector->collectDefinitions($ast, $filepath);
        }

        // Phase 1b.ii: validate defaults-against-bounds at the source level (so a
        // bad declaration like `class Box<T : Stringable = int>` fails BEFORE any
        // padded instantiation is recorded), then collect instantiations -- including
        // bare `new Foo;` shapes for templates whose every param has a default.
        // Variance-position rules (covariant T in input, contravariant T in output,
        // bound/default/property/constructor invariance, F-bounded variance). Moved
        // here from the parser so all definitions are present and `xphp check` can
        // collect across files; compile-mode still throws on the first violation.
        // Runs BEFORE the defaults-vs-bounds check so that, when a class has both,
        // the variance error surfaces first (the order it surfaced at parse time).
        $registry->validateVariancePositions();
        // Undeclared type names in member signatures (a stray/typo'd type param)
        // fail before defaults/instantiation so a non-existent reference never
        // reaches emission as broken PHP.
        $registry->validateUndeclaredTypeParameters();
        $registry->validateDefaultsAgainstBounds();
        // Inner-template variance composition: every template's variance
        // markers are known by now, so cases the parse-time validator
        // couldn't catch (e.g. `class P<out T> { f(): Container<T> }` where
        // Container's slot is invariant) fail here BEFORE instantiations
        // amplify the error.
        $registry->validateInnerVariance();
        // Closure-signature conformance at the statically-visible literal site (a
        // `return`/arrow body whose declared return type is `Closure(...)`).
        // Fail-fast in compile mode (null collector ⇒ throw on the first provable
        // mismatch), matching the other source-level gates. A target that
        // references an enclosing type parameter is checked with those leaves
        // still abstract here (⇒ gradually accepted).
        $closureValidator = new ClosureConformanceValidator($hierarchy);
        foreach ($astPerFile as $filepath => $ast) {
            $closureValidator->validateFile($ast, $filepath, null);
        }
        foreach ($astPerFile as $filepath => $ast) {
            $collector->collectInstantiations($ast, $filepath);
        }

        // Phase 2: fixed-point specialization loop.
        /** @var array<string, \PhpParser\Node\Stmt\ClassLike> $specializedAsts keyed by generated FQCN */
        $specializedAsts = [];
        $closer = new SpecializationCloser($hierarchy, new VarianceSubtyping($hierarchy), $this->specializer, $this->hashLength);
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
                    throw new RuntimeException(
                        Registry::undefinedTemplateMessage($instantiation->templateFqn, $generatedFqn),
                    );
                }

                $substitution = array_combine($definition->typeParamNames(), $instantiation->concreteTypes);
                $specialized = $this->specializer->specialize(
                    $definition->templateAst,
                    $substitution,
                    $this->hashLength,
                );

                $specializedAsts[$generatedFqn] = $specialized;
                $collector->collect([$specialized], "<specialized:{$generatedFqn}>");
            }

            // Close the specialization set under the covariant-upcast implementation requirement: a
            // covariant upcast to an interface specialization carrying an erased (abstract) method
            // needs the concrete supertype specialization that implements it, which the substitution
            // walk above never discovers (an upcast is usage, not substitution). Schedules it here so
            // the next iteration specializes it; the variance edge emitter then inherits the member.
            $closerAdded = $closer->close($registry, $specializedAsts);

            $countAfter = count($registry->instantiations());
            if (!$newlyProcessed && !$closerAdded) {
                break;
            }

            if ($countAfter === $countBefore) {
                continue;
            }

            $depth++;
            if ($depth > self::MAX_SPECIALIZATION_DEPTH) {
                throw new RuntimeException(self::unconvergedSpecializationMessage($registry));
            }
        }

        // Phase 2.4: grounded closure-signature conformance. Each specialization's
        // `Closure(...)` target now has its type parameters substituted, and the
        // returned literal's types were substituted alongside it, so a mismatch
        // that was gradual while the type parameter was abstract (e.g. a `string`
        // literal parameter against a `Closure(T $x)` target grounded to `int`)
        // becomes provable here. Fail-fast, like the pre-loop gate. Structural
        // mismatches (arity / by-ref) don't depend on grounding and were already
        // caught at the template pre-loop, which threw before reaching this point.
        foreach ($specializedAsts as $generatedFqn => $classAst) {
            $closureValidator->validateFile([$classAst], "<specialized:{$generatedFqn}>", null);
        }

        // Phase 2.5: emit subtype edges between specializations whose template
        // declares variance markers. Runs once after the fixed-point loop
        // (Phase 2) finishes -- pairwise variance comparisons can't run until
        // every specialization is recorded. Edges are added to the cloned
        // ClassLike's `implements` / `extends` list and survive CallSiteRewriter
        // (Phase 3) untouched -- CallSiteRewriter only rewrites template
        // Class_/Interface_ nodes, not specialized ones.
        $varianceEmitter = new VarianceEdgeEmitter($hierarchy);
        $varianceEmitter->emitEdges($specializedAsts, $registry);

        // Phase 3: rewrite + emit specialized classes.
        $rewriter = new CallSiteRewriter($registry);
        foreach ($specializedAsts as $generatedFqn => $classAst) {
            $rewritten = $rewriter->rewrite([$classAst]);
            $first = $rewritten[0];
            assert($first instanceof \PhpParser\Node\Stmt\ClassLike);
            $specializedAsts[$generatedFqn] = $first;
        }

        // Phase 3.5: covariant-upcast gap-fill. The inheritance chain is now final and fully qualified, so
        // each concrete spec's erased members that single inheritance couldn't carry across a covariant
        // diamond are supplied directly here — or fail loudly (`xphp.unschedulable_covariant_upcast`),
        // never emitted as a class-load fatal. Re-rewrite the specs it appended a member to so the new
        // member's type references are fully qualified like the rest.
        foreach ($closer->supplyUnmetMembers($registry, $specializedAsts) as $generatedFqn) {
            $rewritten = $rewriter->rewrite([$specializedAsts[$generatedFqn]]);
            $first = $rewritten[0];
            assert($first instanceof \PhpParser\Node\Stmt\ClassLike);
            $specializedAsts[$generatedFqn] = $first;
        }

        // Note for future-proofing (review F9): method-level specialization runs in Phase 1a
        // against the raw user-file ASTs, NOT against the specialized cache classes. That's
        // safe under the current MVP limit ("generic methods on non-generic classes only" —
        // see GenericMethodCompiler's docblock). If that limit ever relaxes, the specialized
        // class ASTs would need to be fed back through the method compiler with their
        // enclosing namespace preserved so FQN keying still works.
        foreach ($specializedAsts as $generatedFqn => $classAst) {
            $this->specializedClassGenerator->emit($classAst, $generatedFqn, $cacheDir);
        }

        // Phase 4: rewrite + emit user source files. Each file's relative (PSR-4) path is computed
        // against its own source root (the manifest/multi-root form), falling back to $sourceDir
        // for the single-dir form. Two roots that would emit a file to the same target path is a
        // hard error, not a silent overwrite.
        $emittedBy = [];
        foreach ($astPerFile as $filepath => $ast) {
            $rewrittenAst = $rewriter->rewrite($ast);
            $code = $this->printer->prettyPrintFile($rewrittenAst);

            $base = $rootByFile[$filepath] ?? $sourceDir;
            $relPath = self::relativePath($base, $filepath);
            $targetPath = rtrim($targetDir, '/') . '/' . preg_replace('/\.xphp$/', '.php', $relPath);

            if (isset($emittedBy[$targetPath])) {
                throw new RuntimeException(sprintf(
                    'Emit path collision: "%s" and "%s" both map to "%s" — two source roots contain '
                    . 'a file at the same relative path. Rename one or separate the roots.',
                    $emittedBy[$targetPath],
                    $filepath,
                    $targetPath,
                ));
            }
            $emittedBy[$targetPath] = $filepath;

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

    /**
     * Validate-only pass for `xphp check`: parse, build the hierarchy, collect definitions,
     * then validate (defaults-vs-bounds) and collect instantiations (bounds, missing args)
     * with a DiagnosticCollector so every generic error is gathered instead of throwing on
     * the first. Stops after validation — it never specializes or emits, so a partially-invalid
     * registry never reaches the fixed-point loop. Returns the collected diagnostics.
     *
     * Includes method/function/closure-level generic checks: GenericMethodCompiler runs in
     * validate-only mode (`emit: false`) so it collects bound / missing-arg / duplicate-function /
     * closure-rejection diagnostics without specializing or emitting.
     *
     * Per-file resilience: a file that fails to parse is reported as a diagnostic and skipped,
     * so the remaining files are still checked (unlike compile(), which fails fast).
     */
    public function check(FilepathArray $sources): DiagnosticCollector
    {
        $diagnostics = new DiagnosticCollector();
        $astPerFile = [];
        foreach ($sources->filepaths as $filepath) {
            // Read OUTSIDE the try so an I/O failure surfaces as itself, not a mislabeled
            // "parse error" — only parsing is treated as a per-file, recoverable diagnostic.
            $content = $this->fileReader->read($filepath);
            try {
                $astPerFile[$filepath] = $this->sourceParser->parse($content);
            } catch (PhpParserError $e) {
                $line = $e->getStartLine();
                $diagnostics->add(new Diagnostic(
                    Severity::Error,
                    self::CODE_PARSE_ERROR,
                    $e->getMessage(),
                    // @infection-ignore-all GreaterThan/IncrementInteger/DecrementInteger -- nikic
                    // emits either a real line (>= 1) or the sentinel -1 for position-less errors;
                    // every `> 0` boundary variant routes -1 to the same `?: 1` fallback, so the
                    // mutants are equivalent. The real-line path is pinned by CheckCommandTest
                    // (Broken.xphp -> line 11).
                    new SourceLocation($filepath, $line > 0 ? $line : 1),
                ));
            } catch (XphpParseException $e) {
                // xphp-specific parse-time rejections from the scanner (e.g. variance markers
                // on methods, malformed generic defaults) — these carry the offending token's
                // original-source line so the diagnostic points at the real site.
                $line = $e->sourceLine();
                $diagnostics->add(new Diagnostic(
                    Severity::Error,
                    self::CODE_PARSE_ERROR,
                    $e->getMessage(),
                    // @infection-ignore-all GreaterThan/IncrementInteger/DecrementInteger -- the
                    // scanner supplies a real line (>= 1) or 0 when no token position was
                    // available; every `> 0` boundary variant routes 0 to the same `?: 1`
                    // fallback, so the mutants are equivalent. The real-line path is pinned by
                    // CheckPassIntegrationTest's testParseTime* cases.
                    new SourceLocation($filepath, $line > 0 ? $line : 1),
                ));
            } catch (RuntimeException $e) {
                // Remaining xphp parse-time rejections that carry no token position (e.g.
                // structural checks over parsed entries) — the diagnostic points at the file
                // (line 1).
                $diagnostics->add(new Diagnostic(
                    Severity::Error,
                    self::CODE_PARSE_ERROR,
                    $e->getMessage(),
                    new SourceLocation($filepath, 1),
                ));
            }
        }

        $hierarchy = TypeHierarchy::fromAstPerFile($astPerFile);
        $registry = new Registry($this->hashLength, $hierarchy, $diagnostics);
        $collector = new RegistryCollector($registry);

        foreach ($astPerFile as $filepath => $ast) {
            $collector->collectDefinitions($ast, $filepath);
        }
        $registry->validateVariancePositions();
        $registry->validateUndeclaredTypeParameters();
        UndeclaredTypeParameterValidator::assertMethodLevel($astPerFile, $hierarchy, $diagnostics);
        $registry->validateDefaultsAgainstBounds();
        $registry->validateInnerVariance();
        // Closure-signature conformance at the statically-visible literal site
        // (a `Closure(...)` return handing back a closure literal). In
        // validate-only mode every violation is collected (parse-failed files were
        // already skipped from $astPerFile above).
        $closureValidator = new ClosureConformanceValidator($hierarchy);
        foreach ($astPerFile as $filepath => $ast) {
            $closureValidator->validateFile($ast, $filepath, $diagnostics);
        }
        foreach ($astPerFile as $filepath => $ast) {
            $collector->collectInstantiations($ast, $filepath);
        }
        $registry->collectUndefinedTemplates($diagnostics);

        // Method/function/closure-level generic checks: run GenericMethodCompiler in validate-only
        // mode (emit: false) so it collects bound / missing-arg / duplicate-function / closure-rejection
        // diagnostics without specializing or mutating the (discarded) AST.
        // @infection-ignore-all FalseValue -- `emit: true` is observably equivalent here: the
        // validation calls (which produce the diagnostics) run in BOTH modes; `emit` only governs
        // append/strip/finalize side-effects on `$astPerFile`, which is local and discarded. So
        // flipping it changes only wasted work, not the collected diagnostics. `emit: false` is the
        // correct (no-wasted-work, no-mutation) choice.
        (new GenericMethodCompiler($this->hashLength, $hierarchy, $diagnostics))->process($astPerFile, emit: false);

        return $diagnostics;
    }

    /**
     * Parse every source file into an AST keyed by filepath.
     *
     * @return array<string, list<\PhpParser\Node\Stmt>>
     */
    private function parseAll(FilepathArray $sources): array
    {
        $astPerFile = [];
        foreach ($sources->filepaths as $filepath) {
            $astPerFile[$filepath] = $this->sourceParser->parse($this->fileReader->read($filepath));
        }

        return $astPerFile;
    }

    private static function relativePath(string $base, string $filepath): string
    {
        $base = rtrim($base, '/') . '/';
        if (str_starts_with($filepath, $base)) {
            return substr($filepath, strlen($base));
        }
        return basename($filepath);
    }

    /**
     * Build a localized diagnostic for a specialization set that didn't converge: identify the type
     * family whose arguments nest the deepest (the tip of the growing tower) and name it, instead of
     * dumping the whole registry. The common cause is a method whose return type re-wraps the receiver's
     * own type family in a growing form (`groupBy(): Map<L, List<E>>` where Map's views re-expose List).
     */
    private static function unconvergedSpecializationMessage(Registry $registry): string
    {
        $deepest = self::deepestInstantiation($registry);
        if ($deepest === null) {
            return sprintf(
                'Generic specialization did not converge (exceeded depth %d): a member\'s type re-wraps '
                . 'the receiver\'s own type family in a growing form. Break the cycle: give the member a '
                . 'non-self-reintroducing type, or split the derivation so the growing type isn\'t reached '
                . 'through an unbounded chain.',
                self::MAX_SPECIALIZATION_DEPTH,
            );
        }

        // @infection-ignore-all ConcatOperandRemoval -- a dropped angle-bracket in the illustrative
        // example type is cosmetic, not a behavior change; the example's shape (root family + nesting) is
        // pinned by the diagnostic test's "ImmutableMap<string," and deep-nesting assertions.
        $example = $deepest->templateFqn . '<' . implode(', ', array_map(
            static fn (TypeRef $r): string => $r->canonical(),
            $deepest->concreteTypes,
        )) . '>';

        // Name every template whose family appears in the top tiers of the growing tower — those are
        // the types whose mutual references form the cycle. Point at each one's source file so the
        // author knows where to break it.
        $cycleDefs = self::cycleDefinitions($registry);
        $families = $cycleDefs === []
            ? ltrim($deepest->templateFqn, '\\')
            : implode(' and ', array_map(
                static fn (GenericDefinition $d): string => sprintf('%s (%s)', $d->templateFqn, $d->sourceFile),
                $cycleDefs,
            ));

        return sprintf(
            'Generic specialization did not converge (exceeded depth %d): a self-reintroducing cycle '
            . 'grows without bound through %s — e.g. "%s". This happens when a member\'s type re-wraps the '
            . 'receiver\'s own type family in a growing form (for example `groupBy(): Map<L, List<E>>`, '
            . 'where Map\'s views re-expose List). Break the cycle: return a non-self-reintroducing type '
            . 'from the re-exposing member (for example a non-generic iterable), or split the derivation '
            . 'so the growing type isn\'t reached through an unbounded chain.',
            self::MAX_SPECIALIZATION_DEPTH,
            $families,
            $example,
        );
    }

    /**
     * The maximum nesting depth across an instantiation's concrete type arguments.
     *
     * @infection-ignore-all DecrementInteger -- the `0` seed is equivalent: a recorded generic
     *     instantiation always has at least one concrete type, so the loop runs and `max()` dominates
     *     the seed; only an (impossible) zero-argument instantiation could observe the seed value.
     */
    private static function instantiationDepth(GenericInstantiation $instantiation): int
    {
        $depth = 0;
        foreach ($instantiation->concreteTypes as $arg) {
            $depth = max($depth, self::typeRefDepth($arg));
        }
        return $depth;
    }

    /**
     * The recorded instantiation whose argument tree nests the deepest (the tip of a growing tower).
     *
     * @infection-ignore-all DecrementInteger/IncrementInteger/GreaterThan -- the `-1` seed and the `>`
     *     vs `>=` tie-break are equivalent under divergence: the loop always finds a strictly-positive
     *     deepest, and ties resolve to an equally-deep instantiation either way. Behaviorally pinned —
     *     the diagnostic test asserts the chosen example carries the deepest (multi-level) nesting.
     */
    private static function deepestInstantiation(Registry $registry): ?GenericInstantiation
    {
        $deepest = null;
        $deepestDepth = -1;
        foreach ($registry->instantiations() as $instantiation) {
            $depth = self::instantiationDepth($instantiation);
            if ($depth > $deepestDepth) {
                $deepestDepth = $depth;
                $deepest = $instantiation;
            }
        }
        return $deepest;
    }

    /**
     * The generic definitions that drive the growing cycle: the concrete classes appearing in the top
     * tiers of the tower (instantiations within one level of the deepest). A single tip type only names
     * the families in *its* branch — e.g. a `List<List<…>>` tip omits the `Map` it alternates with — so
     * the top two tiers are scanned. Interfaces and abstract bases are dragged along as supertypes of the
     * growing classes but don't construct the deeper values, so they are filtered out to keep the
     * diagnostic pointed at the classes whose members re-wrap the family. If nothing concrete remains
     * (the cycle runs purely through interfaces), the unfiltered set is returned rather than nothing.
     * Deduplicated, in first-seen order.
     *
     * @infection-ignore-all DecrementInteger/IncrementInteger/LessThan/LessThanNegotiation/Foreach/Coalesce/UnwrapLtrim/FunctionCallRemoval/UnwrapArrayValues
     *     -- error-path diagnostic only. The family set is collected via redundant paths (each scanned
     *     instantiation's root plus a full walk of its argument tree), so dropping the recursion or a
     *     collection call still names the same families; the `maxDepth - 1` tier window only excludes
     *     unrelated shallow bystanders (none exist in a pure tower); and the `?? ltrim()` / `array_values()`
     *     calls are defensive no-ops on canonical registry names. All behaviorally pinned by the
     *     diagnostic test (names exactly the two concrete cycle classes and excludes the supertypes).
     *
     * @return list<GenericDefinition>
     */
    private static function cycleDefinitions(Registry $registry): array
    {
        $instantiations = $registry->instantiations();
        $maxDepth = 0;
        foreach ($instantiations as $instantiation) {
            $maxDepth = max($maxDepth, self::instantiationDepth($instantiation));
        }

        $defs = [];
        $consider = static function (string $name) use ($registry, &$defs): void {
            $def = $registry->definition($name) ?? $registry->definition(ltrim($name, '\\'));
            if ($def !== null) {
                $defs[$def->templateFqn] = $def;
            }
        };
        $walk = static function (TypeRef $ref) use (&$walk, $consider): void {
            $consider($ref->name);
            foreach ($ref->args as $arg) {
                $walk($arg);
            }
        };
        foreach ($instantiations as $instantiation) {
            if (self::instantiationDepth($instantiation) < $maxDepth - 1) {
                continue;
            }
            $consider($instantiation->templateFqn);
            foreach ($instantiation->concreteTypes as $arg) {
                $walk($arg);
            }
        }

        $concrete = array_values(array_filter(
            $defs,
            static fn (GenericDefinition $d): bool =>
                $d->templateAst instanceof \PhpParser\Node\Stmt\Class_ && !$d->templateAst->isAbstract(),
        ));
        return $concrete !== [] ? $concrete : array_values($defs);
    }

    /** The maximum nesting depth of a TypeRef's generic-argument tree (a non-generic ref is depth 0). */
    private static function typeRefDepth(TypeRef $ref): int
    {
        $max = 0;
        foreach ($ref->args as $arg) {
            $max = max($max, self::typeRefDepth($arg));
        }
        return $ref->args === [] ? 0 : 1 + $max;
    }
}

