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
 *
 * @phpstan-import-type BoundDict from XphpSourceParser
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

        // Phase 1b.i: collect class definitions across every source file. Splitting
        // definitions ahead of instantiations gives bare-`new Foo;` synthesis (added
        // in 1b.ii) a complete template registry so it can recognize Foo as an
        // all-defaulted template regardless of the file-walk order. Collected BEFORE the
        // method compiler runs so the type-argument inference pass below has the full
        // template registry AND sees the original (un-stripped, un-appended) user ASTs —
        // the same shape `check()` runs it against, keeping the two modes in parity.
        foreach ($astPerFile as $filepath => $ast) {
            $collector->collectDefinitions($ast, $filepath);
        }

        // Optional turbofish on `new`: infer a bare `new Box($x)`'s type arguments from its
        // constructor arguments and annotate it, so the instantiation collector + call-site
        // rewriter treat it as an explicit turbofish. A `new` it can't resolve is left bare
        // (all-defaults synthesis / missing-type-argument error). Runs before `process` so it
        // never sees appended specializations (which `check` can't), and before
        // collectInstantiations so the annotation is picked up.
        (new NewInferencePass($registry, $hierarchy))->run($astPerFile);

        $methodCompiler = new GenericMethodCompiler($this->hashLength, $hierarchy);
        $methodCompiler->process($astPerFile);

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

        // Phase 2: fixed-point specialization loop. Fail-fast (an undefined template
        // or an exceeded depth throws) — the emit path must not proceed on a set it
        // couldn't fully build. The method compiler rides along: each fresh
        // specialization is grounded (enclosing-param method-generic turbofish
        // dispatched against the retained Phase-1a template index) before collection.
        $specializedAsts = $this->specializeToFixedPoint($registry, $collector, $hierarchy, resilient: false, methodCompiler: $methodCompiler);

        // Phase 2.3: re-qualify free-function calls and const fetches in every specialization
        // produced by the fixed-point loop. Each body was relocated out of its origin namespace
        // into XPHP\Generated\…, where an unqualified `helper()` / `FOO` would otherwise rebind
        // against the generated namespace and fatal. The guard only qualifies symbols the unit
        // defines. (Members appended later by the covariant-upcast gap-fill are swept in Phase 3.5,
        // since they don't exist yet here.)
        foreach ($specializedAsts as $classAst) {
            Specializer::requalifyFreeSymbols($classAst, $registry);
        }

        // Phase 2.4: grounded closure-signature conformance. Each specialization's
        // `Closure(...)` target now has its type parameters substituted, and the
        // returned literal's types were substituted alongside it, so a mismatch
        // that was gradual while the type parameter was abstract (e.g. a `string`
        // literal parameter against a `Closure(T $x)` target grounded to `int`)
        // becomes provable here. Fail-fast, like the pre-loop gate. Structural
        // mismatches (arity / by-ref) don't depend on grounding and were already
        // caught at the template pre-loop, which threw before reaching this point.
        // @infection-ignore-all TrueValue -- groundedTypesOnly true/false is equivalent
        // HERE: a structural (arity / by-ref) mismatch throws at the abstract pre-loop
        // above and never reaches Phase 2.4, so the type-only path and the full check
        // coincide once execution gets here. `true` states the intent (only leaf types
        // changed under grounding); check()'s grounded pass genuinely needs it.
        foreach ($specializedAsts as $generatedFqn => $classAst) {
            $closureValidator->validateFile([$classAst], "<specialized:{$generatedFqn}>", null, groundedTypesOnly: true);
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
        // member's type references are fully qualified like the rest. (`SpecializationCloser` is stateless,
        // so a fresh instance here is equivalent to the one the fixed-point loop used.)
        $closer = new SpecializationCloser($hierarchy, new VarianceSubtyping($hierarchy), $this->specializer, $this->hashLength);
        foreach ($closer->supplyUnmetMembers($registry, $specializedAsts) as $generatedFqn) {
            // The gap-fill member's body is a relocated template body too, so re-qualify its
            // free-function/const references (Phase 2.3 ran before this member existed). Idempotent
            // on the spec's pre-existing members — their callees are already fully qualified.
            Specializer::requalifyFreeSymbols($specializedAsts[$generatedFqn], $registry);
            $rewritten = $rewriter->rewrite([$specializedAsts[$generatedFqn]]);
            $first = $rewritten[0];
            assert($first instanceof \PhpParser\Node\Stmt\ClassLike);
            $specializedAsts[$generatedFqn] = $first;
        }

        // Method-level specialization runs twice-shaped: Phase 1a against the raw
        // user-file ASTs, then per-specialization inside the Phase-2 loop
        // (GenericMethodCompiler::groundSpecializedClass, fed the retained template
        // index with the spec's identity threaded — the F9 wiring note this replaces).
        // Anything neither pass could ground still carries its marker and is rejected
        // by the backstop below.
        foreach ($specializedAsts as $generatedFqn => $classAst) {
            // Last-resort safety net: no generic marker may survive into emitted output. A
            // surviving turbofish/closure marker is a site the pipeline could not ground —
            // it would print type-parameter hints as references to non-existent classes (a
            // runtime TypeError). Fail loud here instead. (Compile-only; `check` never emits.)
            GenericMarkerLeakGuard::assertNoLeak($classAst, $generatedFqn);
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
    /**
     * Phase 2 — specialize every recorded instantiation to a fixed point,
     * discovering transitively-instantiated generics as it goes (each specialized
     * AST is re-collected, which records the instantiations nested in its body) and
     * closing the set under the covariant-upcast implementation requirement.
     * Returns the specialized ASTs keyed by generated FQCN.
     *
     * Shared by `compile` (which emits from the returned set) and `check` (which
     * grounds closure-signature conformance over it, then discards it):
     *  - `$resilient = false` (compile): an undefined template or an exceeded
     *    specialization depth throws, matching the fail-fast emit path.
     *  - `$resilient = true` (check): an undefined template is skipped (it is
     *    already reported by {@see Registry::collectUndefinedTemplates}), a
     *    specialization that throws is skipped, and hitting the depth cap stops
     *    expansion and returns the set discovered so far — a conformance pass over
     *    a partial set can only miss a provable violation, never invent one, so
     *    `check` stays resilient instead of aborting.
     *
     * @return array<string, \PhpParser\Node\Stmt\ClassLike> keyed by generated FQCN
     */
    private function specializeToFixedPoint(
        Registry $registry,
        RegistryCollector $collector,
        TypeHierarchy $hierarchy,
        bool $resilient,
        ?GenericMethodCompiler $methodCompiler = null,
    ): array {
        /** @var array<string, \PhpParser\Node\Stmt\ClassLike> $specializedAsts keyed by generated FQCN */
        $specializedAsts = [];
        /** @var array<string, true> $skip instantiations that could not be specialized (resilient mode only) */
        $skip = [];
        $closer = new SpecializationCloser($hierarchy, new VarianceSubtyping($hierarchy), $this->specializer, $this->hashLength);
        $depth = 0;
        while (true) {
            $countBefore = count($registry->instantiations());
            $newlyProcessed = false;

            foreach ($registry->instantiations() as $generatedFqn => $instantiation) {
                if (isset($specializedAsts[$generatedFqn]) || isset($skip[$generatedFqn])) {
                    continue;
                }
                $newlyProcessed = true;

                $definition = $registry->definition($instantiation->templateFqn);
                if ($definition === null) {
                    if ($resilient) {
                        $skip[$generatedFqn] = true;
                        continue;
                    }
                    throw new RuntimeException(
                        Registry::undefinedTemplateMessage($instantiation->templateFqn, $generatedFqn),
                    );
                }

                // In resilient mode the registry may hold an arity-mismatched
                // instantiation (a missing or excess type argument) — already reported
                // as its own diagnostic. `compile` rejects those before this loop, so
                // it never sees one; skip it here rather than let array_combine raise a
                // ValueError on unequal key/value counts.
                if ($resilient && count($definition->typeParamNames()) !== count($instantiation->concreteTypes)) {
                    // @infection-ignore-all TrueValue -- `$skip` is a set; only key
                    // existence matters (`isset($skip[...])` above), so the value true/false
                    // is equivalent.
                    $skip[$generatedFqn] = true;
                    // @infection-ignore-all Continue_ -- break vs continue reconverges: the
                    // outer while-loop reprocesses the remaining instantiations on the next
                    // pass (this one is now in `$skip`), yielding the same specialized set.
                    continue;
                }

                $substitution = Substitution::fromNames($definition->typeParamNames(), $instantiation->concreteTypes);
                if ($resilient) {
                    try {
                        $specialized = $this->specializer->specialize($definition->templateAst, $substitution, $this->hashLength);
                    } catch (RuntimeException) {
                        // A specialization that throws is a compile-time error that `compile`
                        // will raise; `check` skips it here so one bad instantiation doesn't
                        // abort the grounded conformance pass over the rest.
                        $skip[$generatedFqn] = true;
                        continue;
                    }
                } else {
                    $specialized = $this->specializer->specialize($definition->templateAst, $substitution, $this->hashLength);
                }

                $specializedAsts[$generatedFqn] = $specialized;

                // Ground method-generic turbofish markers the class substitution just
                // made concrete (`self::gen::<T>` → `::<int>`) BEFORE collecting: an
                // own-template member appended onto the spec is then swept by the
                // collect below, and externally-appended members (onto a non-generic
                // user class or a function namespace — invisible to spec collection)
                // are collected explicitly, so nested instantiation needs discovered by
                // grounding converge through this same fixed point.
                if ($methodCompiler !== null) {
                    if ($resilient) {
                        try {
                            $externalAppends = $methodCompiler->groundSpecializedClass(
                                $specialized,
                                $generatedFqn,
                                $instantiation->templateFqn,
                                $instantiation->concreteTypes,
                                emit: false,
                            );
                        } catch (RuntimeException) {
                            // Grounding failures surface as collected diagnostics in
                            // check mode; a residual throw must not abort the resilient
                            // pass over the remaining instantiations.
                            $externalAppends = [];
                        }
                    } else {
                        $externalAppends = $methodCompiler->groundSpecializedClass(
                            $specialized,
                            $generatedFqn,
                            $instantiation->templateFqn,
                            $instantiation->concreteTypes,
                            emit: true,
                        );
                    }
                    if ($externalAppends !== []) {
                        $collector->collect($externalAppends, "<grounded:{$generatedFqn}>");
                    }
                }

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
                // @infection-ignore-all Continue_ -- break vs continue reconverges: an
                // unchanged count means this pass recorded no new instantiations, so the
                // next iteration processes nothing new and exits via the !newlyProcessed
                // break; the mutant merely skips that no-op pass.
                continue;
            }

            $depth++;
            if ($depth > self::MAX_SPECIALIZATION_DEPTH) {
                if ($resilient) {
                    break;
                }
                throw new RuntimeException(self::unconvergedSpecializationMessage($registry));
            }
        }

        return $specializedAsts;
    }

    public function check(FilepathArray $sources): DiagnosticCollector
    {
        $diagnostics = new DiagnosticCollector();
        // Read every source up front — OUTSIDE the try so an I/O failure surfaces as itself, not a
        // mislabeled "parse error" — then merge a whole-program alias table so a cross-file alias use
        // resolves. Only parsing is treated as a per-file, recoverable diagnostic.
        $contents = [];
        foreach ($sources->filepaths as $filepath) {
            $contents[$filepath] = $this->fileReader->read($filepath);
        }
        $globalAliases = $this->collectGlobalAliases($contents);
        $astPerFile = [];
        foreach ($contents as $filepath => $content) {
            try {
                $astPerFile[$filepath] = $this->sourceParser->parse($content, $globalAliases);
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
                // original-source line so the diagnostic points at the real site, and optionally a
                // stable diagnostic code (e.g. a type-alias rejection) in place of the generic one.
                $line = $e->sourceLine();
                $diagnostics->add(new Diagnostic(
                    Severity::Error,
                    $e->diagnosticCode() ?? self::CODE_PARSE_ERROR,
                    $e->getMessage(),
                    // @infection-ignore-all GreaterThan/IncrementInteger/DecrementInteger -- every
                    // current throw site supplies a real token line (>= 1), so this `> 0` guard is
                    // a defensive floor for the exception's documented 0 ("no position") contract.
                    // The fallback value equals the boundary (1), so shifting or flipping the `> 0`
                    // test routes to the same result — the mutants are equivalent. The real-line
                    // path is pinned by CheckPassIntegrationTest's testParseTime* cases.
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
        // Optional turbofish on `new` (see compile()): infer bare `new` type arguments before
        // instantiations are collected. Same pipeline position as compile — after definitions,
        // before collectInstantiations — so check and compile infer identically.
        (new NewInferencePass($registry, $hierarchy))->run($astPerFile);
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
        // correct (no-wasted-work, no-mutation) choice. The instance is held: the resilient
        // specialization pass below feeds each spec back through it (groundSpecializedClass) so
        // enclosing-param turbofish diagnostics only provable after substitution are collected —
        // keeping check's verdicts aligned with compile's.
        $methodCompiler = new GenericMethodCompiler($this->hashLength, $hierarchy, $diagnostics);
        $methodCompiler->process($astPerFile, emit: false);

        // Grounded closure-signature conformance. A `Closure(T $x)` target whose
        // type parameter is still abstract above is gradually accepted; grounding it
        // per specialization (e.g. `Registry<string>` ⇒ `Closure(string $x)`) can
        // turn a previously-unprovable literal mismatch into a provable one. `compile`
        // catches this in Phase 2.4; `check` must too, or it silently passes code
        // that `compile` rejects (and the compile-driven PHPStan gate would surface
        // the same reject as a raw exception rather than a diagnostic). Specialize to
        // a fixed point in resilient mode — discovering transitively-instantiated
        // generics, not just source-visible ones — then run the type-relation half of
        // conformance over every specialization in collector mode. Structural (arity /
        // by-ref) mismatches were already collected by the abstract pre-loop above, so
        // the grounded pass skips them to avoid a duplicate report at the specialized
        // location.
        $groundedAsts = $this->specializeToFixedPoint($registry, $collector, $hierarchy, resilient: true, methodCompiler: $methodCompiler);
        foreach ($groundedAsts as $generatedFqn => $classAst) {
            $closureValidator->validateFile([$classAst], "<specialized:{$generatedFqn}>", $diagnostics, groundedTypesOnly: true);
        }

        return $diagnostics;
    }

    /**
     * Parse every source file into an AST keyed by filepath.
     *
     * @return array<string, list<\PhpParser\Node\Stmt>>
     */
    private function parseAll(FilepathArray $sources): array
    {
        $contents = [];
        foreach ($sources->filepaths as $filepath) {
            $contents[$filepath] = $this->fileReader->read($filepath);
        }
        $globalAliases = $this->collectGlobalAliases($contents);

        $astPerFile = [];
        foreach ($contents as $filepath => $content) {
            $astPerFile[$filepath] = $this->sourceParser->parse($content, $globalAliases);
        }

        return $astPerFile;
    }

    /**
     * Merge every source's file-local type-alias table into one whole-program table, so an alias
     * declared in one file can be used in another. A file whose own aliases are malformed (same-file
     * duplicate / collision / unsupported body) raises here and is skipped — the same error
     * re-surfaces (and, in check mode, is collected) when that file is parsed for real.
     *
     * @param array<string, string> $contents filepath => source
     * @return array<string, array{params:list<array{name:string, bound:?BoundDict, default:?\XPHP\Transpiler\Monomorphize\TypeRef, variance:\XPHP\Transpiler\Monomorphize\Variance}>, body:list<\XPHP\Transpiler\Monomorphize\TypeRef>}>
     */
    private function collectGlobalAliases(array $contents): array
    {
        $global = [];
        foreach ($contents as $content) {
            try {
                foreach ($this->sourceParser->aliasTableOf($content) as $fqn => $entry) {
                    $global[$fqn] = $entry;
                }
            } catch (RuntimeException) {
                // Any parse-time rejection — skip this file's aliases; the same error re-surfaces (and
                // is collected in check mode) when the file is parsed for real. Both a nikic syntax
                // error (PhpParser\Error) and an xphp scanner/alias error (XphpParseException) extend
                // RuntimeException, so this catches every parse-time failure.
            }
        }

        return $global;
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

