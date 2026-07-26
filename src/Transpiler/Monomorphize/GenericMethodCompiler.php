<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Finally_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\While_;
use PhpParser\Node\UseItem;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use RuntimeException;
use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\Diagnostics\Severity;
use XPHP\Diagnostics\SourceLocation;

/**
 * Specializes method-scoped generics: `function NAME<T>(...)` inside a class body, called via
 * `ClassFqn::NAME<int>(...)`.
 *
 * The pass runs in two stages. `process()` walks the raw per-file user ASTs (Phase 1a,
 * before class specialization):
 *   1. Collect every generic-method template — keyed by "classFqn::methodName".
 *   2. Collect every StaticCall carrying ATTR_METHOD_GENERIC_ARGS — derive (classFqn,
 *      methodName, args), generate a mangled method (cloning the template, substituting
 *      the type-param, renaming), append it to the owning class AST — then drain the
 *      appends, re-walking each freshly specialized body so a forward that substitution
 *      just made concrete dispatches too.
 *   3. Strip the original generic-method ClassMethod from each class.
 *   4. Rewrite each StaticCall's Identifier name to the mangled form.
 * Then `groundSpecializedClass()` runs per fresh class specialization inside the
 * fixed-point loop, against the retained template index: a turbofish grounded by an
 * ENCLOSING class type parameter (`self::gen::<T>` inside `Box<T>`) is abstract during
 * Phase 1a and only becomes dispatchable once `Box<int>`'s substitution rewrites the
 * marker — own-template members land on the specialization itself, external targets on
 * the retained user ASTs.
 *
 * Supported call shapes: static (`ClassFqn::method::<int>(...)`), instance and nullsafe
 * (`$obj->method::<int>(...)`, `$obj?->method::<int>(...)`) via receiver-type analysis, and
 * free functions / generic closures / arrows. A generic method declared on a generic OR a
 * non-generic class works; the method's own type-params are a scope disjoint from the class's,
 * so `class Box<T> { public function map<U>(...) }` specializes `U` independently of `T`.
 *
 * Inheritance: an instance/nullsafe turbofish call resolves the method through the receiver's
 * ancestor chain (`resolveMethodTemplate`), and the specialization is emitted onto the
 * *declaring* class so every subclass inherits the single copy. A subclass override (same
 * method redeclared) shadows the inherited one.
 *
 * Limitations:
 *  - Bound validation on method-level type-params fires when a TypeHierarchy is wired in
 *    (compiler always passes one); if none is given (bare unit tests), bounds become
 *    advisory — matching the class-level Registry's behavior.
 *  - Inheritance resolution walks `extends`/`implements` ancestors only; a generic method
 *    reached solely through a `use`d trait is not resolved (the trait isn't in the hierarchy).
 */
final class GenericMethodCompiler
{
    /** Stable diagnostic codes for method/function/closure-level generic errors collected by `check`. */
    public const CODE_DUPLICATE_GENERIC_FUNCTION = 'xphp.duplicate_generic_function';
    public const CODE_UNSUPPORTED_THIS_CAPTURE = 'xphp.closure_this_capture';
    public const CODE_UNSUPPORTED_STATIC_CLOSURE = 'xphp.static_closure';
    public const CODE_UNRESOLVED_GENERIC_CALL = 'xphp.unresolved_generic_call';
    public const CODE_BOUND_UNPROVABLE = 'xphp.bound_unprovable';
    public const CODE_UNDETERMINED_RECEIVER = 'xphp.undetermined_receiver';
    public const CODE_UNSPECIALIZABLE_SELF_CALL = 'xphp.unspecializable_self_call';
    public const CODE_UNSPECIALIZED_GENERIC_CLOSURE = 'xphp.unspecialized_generic_closure';
    public const CODE_UNCONVERGED_METHOD_SPECIALIZATION = 'xphp.unconverged_method_specialization';

    /**
     * Cap on the append-drain's specialization chain depth. A freshly specialized
     * function/method body may itself carry a now-concrete turbofish that mints a further
     * specialization (`wrap<T>` forwarding to `mid::<T>` forwarding to `identity::<T>`);
     * same-args cycles terminate via the alreadyGenerated dedup, but a strictly-growing
     * chain (`grow<T>` calling `grow::<Box<T>>`) mints a new mangled name every hop and
     * would never converge. Sixteen mirrors Compiler::MAX_SPECIALIZATION_DEPTH (kept as a
     * separate constant — this pass must stay independent of the class-level pipeline).
     */
    private const MAX_METHOD_SPECIALIZATION_HOPS = 16;

    /**
     * @param ?DiagnosticCollector $diagnostics When null (the default — `xphp compile`), every
     *   method/function/closure-level generic error throws as before, byte-identical. When provided
     *   (by `xphp check` with `process(..., emit: false)`), each is appended as a Diagnostic and the
     *   pass continues, so all are reported in one run.
     */
    /**
     * Phase-1a state retained for the post-specialization grounding pass
     * ({@see groundSpecializedClass}). `process()` strips generic templates from the
     * user ASTs at the end of its run, but the index keeps referencing the detached
     * template nodes — retaining it is what lets a marker that only became concrete
     * under class specialization still find its method/function template. The dedup
     * map is shared too, so a member a Phase-1a call already appended (or another
     * specialization already grounded) is never appended twice.
     */
    private ?TemplateIndex $retainedIndex = null;
    /** @var array<string, true> shared specialization-dedup keys (see rewriteStaticCall) */
    private array $alreadyGenerated = [];
    /** @var array<string, string> class template FQN => source ast key (filepath), for grounding-time diagnostics */
    private array $classSourceByFqn = [];

    public function __construct(
        private readonly int $hashLength = Registry::DEFAULT_HASH_HEX_LENGTH,
        private readonly ?TypeHierarchy $hierarchy = null,
        private readonly ?DiagnosticCollector $diagnostics = null,
    ) {
    }

    /**
     * Run the pass against the entire AST set (both rewritten user files and specialized
     * cache classes). Mutates the AST nodes in place — class bodies gain mangled methods,
     * StaticCall identifiers get renamed, generic-method templates get removed.
     *
     * @param array<string, list<Node\Stmt>> $astSet keyed by an arbitrary string id (filepath
     *     or "<specialized:fqcn>"). The values are the top-level statements of each AST.
     * @param bool $emit When true (default, compile) the pass specializes, appends, and strips
     *   templates as before. When false (`xphp check`) it walks for VALIDATION only — no append-flush,
     *   no strip, no closure-dispatcher finalize. The traversal still rewrites call-site nodes on the
     *   (discarded) per-file AST, but templates are deep-cloned so nothing shared is mutated; only
     *   diagnostics are produced.
     */
    public function process(array &$astSet, bool $emit = true): void
    {
        /** @var array<string, ClassMethod> $methodTemplates keyed by "classFqn::methodName" */
        $methodTemplates = [];
        /** @var array<string, ClassLike> $classByFqn */
        $classByFqn = [];
        /** @var array<string, Function_> $functionTemplates keyed by namespace\\functionName */
        $functionTemplates = [];
        /** @var array<string, ?Namespace_> $functionNamespaceByFqn  enclosing Namespace_ per fqn, or null for bare top-level functions */
        $functionNamespaceByFqn = [];
        /** @var array<string, string> $functionAstKeyByFqn  ast-key (filepath) per fqn for top-level (null-namespace) templates, so the strip+append step knows which AST to mutate */
        $functionAstKeyByFqn = [];
        /** @var array<string, string> $functionSourceByFqn  ast-key (filepath or "<specialized:…>") per fqn — used to point duplicate-declaration errors at both source locations */
        $functionSourceByFqn = [];

        foreach ($astSet as $astKey => $ast) {
            $perFileMethods = [];
            $perFileClasses = [];
            $perFileFns = [];
            $perFileFnNs = [];
            $this->indexTemplates($ast, $perFileMethods, $perFileClasses, $perFileFns, $perFileFnNs);

            // Surface duplicate generic-function declarations (same FQN in two source
            // files) with both paths, matching the shape `Registry::recordDefinition`
            // uses for generic classes. Silently overwriting the first body — which is
            // the prior behavior here — costs a real refactoring footgun.
            foreach ($perFileFns as $fqn => $duplicate) {
                if (isset($functionTemplates[$fqn])) {
                    $message = sprintf(
                        'Generic function template "%s" already declared (in %s); duplicate declaration in %s.',
                        $fqn,
                        $functionSourceByFqn[$fqn],
                        (string) $astKey,
                    );
                    if ($this->diagnostics !== null) {
                        $this->diagnostics->add(new Diagnostic(
                            Severity::Error,
                            self::CODE_DUPLICATE_GENERIC_FUNCTION,
                            $message,
                            new SourceLocation((string) $astKey, $duplicate->getStartLine()),
                        ));
                        continue;
                    }
                    throw new RuntimeException($message);
                }
            }

            foreach ($perFileMethods as $k => $v) {
                $methodTemplates[$k] = $v;
            }
            foreach ($perFileClasses as $k => $v) {
                $classByFqn[$k] = $v;
                $this->classSourceByFqn[$k] = (string) $astKey;
            }
            foreach ($perFileFns as $k => $v) {
                $functionTemplates[$k] = $v;
                $functionSourceByFqn[$k] = (string) $astKey;
                $functionAstKeyByFqn[$k] = (string) $astKey;
            }
            foreach ($perFileFnNs as $k => $v) {
                $functionNamespaceByFqn[$k] = $v;
            }
        }

        // Closure-template tracking happens lazily inside rewriteCallSites
        // (every Assign with a Closure-with-genericParams RHS is tracked), so
        // the early return must NOT fire just because the file has no named
        // templates -- it might still have anonymous generic closures. It must
        // also stay alive when a `Closure(...)`-typed PARAMETER exists anywhere:
        // a plain call to such a (non-generic) method still needs its closure-
        // literal arguments conformance-checked (checkPlain*ClosureArgs).
        if ($methodTemplates === [] && $functionTemplates === []
            && !self::hasAnonymousGenericCallSite($astSet)
            && !self::hasClosureTypedParameter($astSet)
        ) {
            return;
        }

        // Every free function by FQN (generic OR not), so a plain call to a NON-generic
        // free function can reach its declared parameters for closure-argument conformance
        // (checkPlainFreeFunctionClosureArgs). `$functionTemplates` holds generics only.
        $allFunctionsByFqn = self::indexAllFunctions($astSet);

        // Bundle the five read-only symbol tables once, after the per-file merge, and thread
        // them as a single value into the rewriter. The raw locals stay in scope here for the
        // duplicate-declaration diagnostic (above) and the template-stripping loops (below),
        // which iterate the maps — a responsibility outside this read-only value object.
        $index = new TemplateIndex(
            $methodTemplates,
            $classByFqn,
            $functionTemplates,
            $functionNamespaceByFqn,
            $allFunctionsByFqn,
        );
        // Retain for the post-specialization grounding pass; the template nodes stay
        // reachable through the index even after the strip loops below detach them.
        $this->retainedIndex = $index;

        $alreadyGenerated = &$this->alreadyGenerated;
        foreach ($astSet as $astKey => &$ast) {
            // For top-level (null-namespace) functions: the visitor's pendingAppends
            // mechanism mutates a container's ->stmts; the top-level AST is a plain
            // array with no container. We catch top-level appends in a separate bag
            // and flush them by direct array mutation after the traversal completes.
            /** @var list<Function_> $topLevelAppends */
            $topLevelAppends = [];
            $this->rewriteCallSites(
                $ast,
                $index,
                $alreadyGenerated,
                $topLevelAppends,
                (string) $astKey,
                $emit,
            );
            if ($emit) {
                foreach ($topLevelAppends as $specialized) {
                    $ast[] = $specialized;
                }
            }
        }
        unset($ast);

        // Validate-only (check) stops here: no template stripping or append-flush. The discarded
        // per-file AST may carry the traversal's in-place call-site rewrites, but nothing shared is.
        if (!$emit) {
            return;
        }

        // Strip the original method templates from their owning classes.
        foreach ($methodTemplates as $key => $template) {
            $parsed = MethodKey::parse($key);
            $class = $classByFqn[$parsed->classFqn] ?? null;
            if ($class === null) {
                continue;
            }
            // An erasable `<U : E>` method is KEPT on its (generic) class so the Specializer can erase
            // it into a concrete `contains_T_<hash>(E)` member per instantiation. The generic class is
            // lowered to a marker interface in the user file, so the kept template never reaches output.
            if (!self::isErasableMethodOnClass($template, $class)) {
                $this->stripMethod($class, $parsed->method);
            }
        }

        // Strip the original function templates: namespaced ones get stripped from
        // their owning Namespace_; top-level (null-namespace) ones get stripped from
        // the top-level AST array directly via the saved astKey.
        foreach ($functionTemplates as $fqn => $template) {
            $namespace = $functionNamespaceByFqn[$fqn] ?? null;
            if ($namespace !== null) {
                $this->stripFunction($namespace, $template->name->toString());
                continue;
            }
            $astKey = $functionAstKeyByFqn[$fqn] ?? null;
            if ($astKey !== null && isset($astSet[$astKey])) {
                $astSet[$astKey] = self::stripTopLevelFunction(
                    $astSet[$astKey],
                    $template->name->toString(),
                );
            }
        }
    }

    /**
     * Ground + dispatch the method-generic turbofish markers inside one freshly
     * specialized class.
     *
     * Runs from the fixed-point specialization loop, right after the class substitution
     * and BEFORE the spec is collected: a marker like `self::gen::<T>` or
     * `Maker::wrap::<T>` is abstract at the Phase-1a walk (the enclosing `T` has no
     * value in the template) and only becomes dispatchable here, once the substitution
     * has rewritten it to `::<int>`. Re-uses the Phase-1a rewrite machinery against the
     * retained template index, in the markers-only mode the append-drain introduced,
     * plus a {@see GroundingContext} that redirects own-template member appends onto the
     * spec itself (the template class lowers to a marker interface in output) and
     * records externally-appended members so the caller can collect their nested
     * instantiation needs into the same fixed point.
     *
     * Shapes deliberately NOT grounded here keep their marker and fall to the emit
     * backstop exactly as before: instance-call markers, static calls whose declaring
     * class is a *different* generic template, `static::`/`parent::` spellings (their
     * default resolution would silently mis-ground, not fail), and forwards to a bare
     * top-level function template (no container to append to from a detached walk).
     *
     * In `check` mode (`$emit = false`) nothing is attached; diagnostics only provable
     * after substitution (a violated bound, a surviving marker) are collected so check
     * and compile agree.
     *
     * @param list<TypeRef> $classArgs the instantiation's concrete type arguments,
     *                                 parallel to the template's declared parameters
     * @return list<Node\Stmt> members appended onto containers other than the spec
     *                         (user classes / namespaces) — the caller must collect
     *                         these for nested instantiation discovery
     */
    public function groundSpecializedClass(
        ClassLike $specialized,
        string $generatedFqn,
        string $templateFqn,
        array $classArgs,
        bool $emit,
    ): array {
        $index = $this->retainedIndex;
        if ($index === null) {
            // process() never built an index (no templates anywhere) — with no method
            // or function templates in the program there is nothing a marker could
            // dispatch to; any survivor is the emit backstop's to report.
            return [];
        }
        // Cheap pre-scan: most specs carry no marker; skip the visitor entirely then.
        if (GenericMarkerLeakGuard::findLeak($specialized, includeClosureTemplates: false) === null) {
            return [];
        }

        $currentFile = $this->classSourceByFqn[$templateFqn] ?? "<specialized:{$generatedFqn}>";
        $grounding = new GroundingContext($specialized, $generatedFqn, $templateFqn, $classArgs);
        /** @var list<Function_> $topLevelAppends never grows in grounding mode (bare-template forwards keep their marker) */
        $topLevelAppends = [];
        $this->rewriteCallSites(
            [$specialized],
            $index,
            $this->alreadyGenerated,
            $topLevelAppends,
            $currentFile,
            $emit,
            $grounding,
        );

        // Check-mode parity backstop: compile rejects a still-marked spec at the emit
        // loop's assertNoLeak; check has no emit loop, so collect the equivalent
        // diagnostic here (call-marker arm only; sites the Phase-1a walk already
        // reported — e.g. an unspecializable `$this` self-call — dedupe by position).
        if (!$emit && $this->diagnostics !== null) {
            // phpstan memoizes the identical pre-scan call above, but the grounding walk
            // mutates the AST in between: a marker the pre-scan found is usually
            // dispatched (nulled) by now, so this CAN be null.
            $leak = GenericMarkerLeakGuard::findLeak($specialized, includeClosureTemplates: false);
            // @phpstan-ignore-next-line notIdentical.alwaysTrue
            if ($leak !== null && !$this->alreadyReportedAt($currentFile, $leak->getStartLine())) {
                $this->diagnostics->add(new Diagnostic(
                    Severity::Error,
                    GenericMarkerLeakGuard::CODE,
                    GenericMarkerLeakGuard::leakMessage($leak, $generatedFqn),
                    new SourceLocation($currentFile, $leak->getStartLine()),
                ));
            }
        }

        return $grounding->externalAppends;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @param array<string, ClassMethod> $methodTemplates  out-param
     * @param array<string, ClassLike> $classByFqn         out-param
     * @param array<string, Function_> $functionTemplates  out-param
     * @param array<string, ?Namespace_> $functionNamespaceByFqn  out-param (null = bare top-level)
     */
    private function indexTemplates(
        array $ast,
        array &$methodTemplates,
        array &$classByFqn,
        array &$functionTemplates,
        array &$functionNamespaceByFqn,
    ): void {
        // @infection-ignore-all — visitor body is a flat AST walk: every guard either
        // (a) survives because the outer pipeline's tests prove the contract end-to-end,
        // or (b) toggles a defensive isset/`?->` check whose alternate branch is
        // unreachable from valid xphp source (the surrounding integration tests would
        // already have failed before we got here).
        $visitor = new class extends NodeVisitorAbstract {
            private string $currentNamespace = '';
            private ?Namespace_ $currentNamespaceNode = null;
            /** @var array<string, ClassMethod> */
            public array $methodTemplates = [];
            /** @var array<string, ClassLike> */
            public array $classByFqn = [];
            /** @var array<string, Function_> */
            public array $functionTemplates = [];
            /** @var array<string, ?Namespace_> */
            public array $functionNamespaceByFqn = [];
            private ?string $currentClassFqn = null;

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    $this->currentNamespaceNode = $node;
                }
                if ($node instanceof ClassLike && $node->name !== null) {
                    $this->currentClassFqn = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $node->name->toString()
                        : $node->name->toString();
                    $this->classByFqn[$this->currentClassFqn] = $node;
                }
                if ($node instanceof ClassMethod) {
                    $params = $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                    if (is_array($params) && $params !== [] && $this->currentClassFqn !== null) {
                        $key = (string) new MethodKey($this->currentClassFqn, $node->name->toString());
                        $this->methodTemplates[$key] = $node;
                    }
                }
                if ($node instanceof Function_) {
                    $params = $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                    if (is_array($params) && $params !== []) {
                        $fqn = $this->currentNamespace !== ''
                            ? $this->currentNamespace . '\\' . $node->name->toString()
                            : $node->name->toString();
                        $this->functionTemplates[$fqn] = $node;
                        // null = bare top-level (no enclosing `namespace { }` block);
                        // the outer process() handles strip + append for that case.
                        $this->functionNamespaceByFqn[$fqn] = $this->currentNamespaceNode;
                    }
                }
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof ClassLike) {
                    $this->currentClassFqn = null;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        foreach ($visitor->methodTemplates as $k => $v) {
            $methodTemplates[$k] = $v;
        }
        foreach ($visitor->classByFqn as $k => $v) {
            $classByFqn[$k] = $v;
        }
        foreach ($visitor->functionTemplates as $k => $v) {
            $functionTemplates[$k] = $v;
        }
        foreach ($visitor->functionNamespaceByFqn as $k => $v) {
            $functionNamespaceByFqn[$k] = $v;
        }
    }

    /**
     * @param list<Node\Stmt> $ast
     * @param array<string, true> $alreadyGenerated
     * @param list<Function_> $topLevelAppends  out-param: specializations for null-namespace
     *   templates; the caller flushes these to the top-level AST after the traversal completes
     */
    private function rewriteCallSites(
        array $ast,
        TemplateIndex $index,
        array &$alreadyGenerated,
        array &$topLevelAppends,
        string $currentFile,
        bool $emit,
        ?GroundingContext $grounding = null,
    ): void {
        $hashLength = $this->hashLength;
        $hierarchy = $this->hierarchy;
        $diagnostics = $this->diagnostics;
        // The conformance check needs a TypeHierarchy for the engine's class-subtype
        // relations; without one (bare unit tests) closure-argument conformance is
        // skipped (gradual), matching how bound validation degrades here.
        $closureValidator = $hierarchy !== null ? new ClosureConformanceValidator($hierarchy) : null;
        // @infection-ignore-all — see rationale above the indexTemplates visitor: defensive
        // guards and call-shape mutations are masked by the surrounding pipeline's
        // type-strict invariants. End-to-end coverage from GenericMethodIntegrationTest.
        $visitor = new class($index, $alreadyGenerated, $hashLength, $hierarchy, $topLevelAppends, $diagnostics, $currentFile, $closureValidator) extends NodeVisitorAbstract {
            private string $currentNamespace = '';
            private ?Namespace_ $currentNamespaceNode = null;
            /** @var array<string, string> alias => fqn */
            private array $useMap = [];
            /**
             * Parallel to the raw `currentNamespace`/`useMap` tracking, kept in step so a
             * closure-literal call argument resolves its own types against the CALLER's
             * namespace + imports (where the literal is written) when fed to
             * {@see ClosureConformanceValidator::checkCallArguments}.
             */
            private NamespaceContext $nsContext;

            /**
             * Buffered specialized-member appends, flushed (and drained for freshly
             * grounded markers) after the traversal. Slot 2 is the drain context: the
             * declaring class FQN (methods; null for functions) and the namespace the
             * specialized body resolves against — a drained stmt is traversed DETACHED,
             * so the visitor's namespace/class state must be primed per item rather
             * than inherited from whatever file the main walk last visited.
             *
             * @var list<array{0: ClassLike|Namespace_, 1: ClassMethod|Function_, 2: array{classFqn: ?string, namespace: string}}>
             */
            public array $pendingAppends = [];

            /**
             * Markers-only mode for drain re-traversals of freshly specialized bodies.
             * When true the rewrite pass touches ONLY named-call turbofish markers that
             * substitution has made concrete; everything else — plain-call closure-arg
             * sweeps, closure-dispatcher tracking (finalize has already run), orphan
             * re-checks, static/instance marker rewrites (their name resolution is not
             * drain-safe yet; a kept marker falls to the leak guard exactly as before) —
             * is skipped so a drained body can neither duplicate diagnostics already
             * reported against the template nor mis-ground through stale file state.
             */
            public bool $markersOnly = false;

            /**
             * Set (together with markersOnly) when this walk grounds a freshly
             * specialized CLASS ({@see GenericMethodCompiler::groundSpecializedClass}).
             * Widens the markers-only pass to static-call markers — their resolution
             * is drain-safe here because names are attribute-resolved and the spec's
             * own identity is threaded — and drives the append-target rule: an
             * own-template member lands on the spec, deduped per specialization.
             */
            public ?GroundingContext $grounding = null;

            /** Receiver-type analysis state. Pushed on entering ClassLike, popped on leave. */
            private ?string $currentClassFqn = null;
            /**
             * Local scope: parameter-name => resolved-class-FQN. Populated on entering a
             * Function_ / ClassMethod by walking its `params` list and resolving each typed
             * parameter. Used for receiver-type analysis on `$paramName->method::<T>(...)`.
             *
             * @var array<string, string>
             */
            private array $currentScopeParamTypes = [];
            /**
             * Stage B local flow typing: variable-name => resolved-class-FQN. Populated by
             * `enterNode` when it sees an `Assign($var, New_($className))` -- the lexical
             * last-write determines the receiver type at later call sites in the same scope.
             *
             * Each Function_ / ClassMethod / Closure / ArrowFunction pushes a fresh scope
             * onto `$scopeSnapshots`; the parent scope is restored on leave. Without the
             * closure/arrow push, an inner `$x = new Bar()` overwrites the outer scope's
             * `$x` slot, and the receiver type at a later outer call site picks the wrong
             * class (the original review of b88539c caught this exact bug).
             *
             * @var array<string, string>
             */
            private array $currentScopeLocalTypes = [];
            /**
             * Parallel side-tables to the two type maps above, carrying each tracked receiver's
             * generic type arguments (`$b` of declared type `Box<Product>` → `[Product]`). Kept in
             * lockstep with the string maps through scope push/restore and branch reset/leave; a
             * branch that assigns a variable DROPS its args (never merges them), so a post-branch
             * receiver falls back to lenient grounding rather than risking a stale/ambiguous arg.
             * Read by `resolveReceiverTypeArgs` to ground a method-generic bound that references an
             * enclosing class type parameter against the receiver's concrete arguments.
             *
             * @var array<string, list<TypeRef>>
             */
            private array $currentScopeParamTypeArgs = [];
            /** @var array<string, list<TypeRef>> */
            private array $currentScopeLocalTypeArgs = [];
            /**
             * Memo for {@see resolveCallReturn}, keyed by `spl_object_id` of the call node. A call
             * node sits at one fixed program point, so its declared-return-type resolution is
             * deterministic; without the memo a chained receiver re-descends both the FQN and the
             * args branch at every hop, which is O(2^N) in chain depth. With it, each hop resolves
             * once. The value is the resolution result (or `null`); presence is tested with
             * `array_key_exists` so a cached `null` is honoured.
             *
             * @var array<int, array{0: string, 1: list<TypeRef>}|null>
             */
            private array $callReturnCache = [];
            /**
             * Variable name -> the Closure or ArrowFunction AST node that was
             * assigned to it (only when the closure carries
             * ATTR_METHOD_GENERIC_PARAMS, i.e. is a generic anonymous template).
             * Lets the FuncCall-on-Variable rewriter find the template for
             * `$var::<T>(...)` call sites assigned earlier in the same scope.
             *
             * @var array<string, Closure|ArrowFunction>
             */
            private array $currentScopeClosureTemplates = [];
            /**
             * Parallel to `currentScopeClosureTemplates`: the Assign node and
             * lexical-scope info that introduced each generic anonymous template.
             * Populated alongside the template; consumed by the dispatcher
             * finalize phase to know where to patch the original Assign's RHS
             * and where to append specialized declarations.
             *
             * @var array<string, array{assign: Assign, namespace: string, namespaceNode: ?Namespace_}>
             */
            private array $currentScopeClosureContexts = [];
            /**
             * Per-template dispatch plan keyed by `varName . '@' . startFilePos`
             * so two same-named templates in different scopes don't collide.
             * Each entry collects the arg-tuples seen at call sites and the
             * FuncCall nodes themselves, then the post-traversal finalize
             * phase materializes one dispatcher closure per entry.
             *
             * @var array<string, array{
             *   template: Closure|ArrowFunction,
             *   varName: string,
             *   assignNode: Assign,
             *   namespace: string,
             *   namespaceNode: ?Namespace_,
             *   typeParams: list<TypeParam>,
             *   callSites: list<FuncCall>,
             *   argSets: list<list<TypeRef>>,
             *   seenTagSet: array<string, true>
             * }>
             */
            public array $closureDispatchPlan = [];
            /**
             * spl_object_id set of closure/arrow templates some call site
             * ATTEMPTED to specialize (turbofish lookup or bare-call
             * resolution) — even when that call then drew its own reject
             * (static closure, `$this` capture, missing turbofish). Read
             * after the walk: a generic template never attempted anywhere
             * is an orphan that would emit raw type-param hints.
             *
             * @var array<int, true>
             */
            public array $attemptedClosureTemplates = [];
            /**
             * Snapshot stack for scope isolation across nested
             * Function_/ClassMethod/Closure/ArrowFunction boundaries. On enter we push the
             * outgoing `(params, locals, branches)` triple; on leave we pop and restore.
             * Branch snapshots are nested per-scope so that branches inside a closure
             * don't leak to branches in the enclosing function. The closure-template maps
             * are snapshotted too so a generic closure assigned in one scope doesn't leak
             * into a sibling scope where the same variable names an unrelated callable.
             *
             * @var list<array{params: array<string,string>, locals: array<string,string>, paramArgs: array<string, list<TypeRef>>, localArgs: array<string, list<TypeRef>>, branches: list<array{snapshot: array<string,string>, localArgsSnapshot: array<string, list<TypeRef>>, assigned: array<string,bool>, perBranchTypes: list<array<string, ?string>>, perBranchArgs: list<array<string, ?list<TypeRef>>>, armIndex: int}>, closureTemplates: array<string, Closure|ArrowFunction>, closureContexts: array<string, array{assign: Assign, namespace: string, namespaceNode: ?Namespace_}>}>
             */
            private array $scopeSnapshots = [];
            /**
             * Branch frame stack for conservative reasoning across mutually-exclusive
             * control-flow constructs (if/elseif/else, switch/case, match arms,
             * while/for/foreach/do-while loops, try/catch/finally). Each frame holds:
             *   - `snapshot`: the value of `$currentScopeLocalTypes` at the moment the
             *     branching construct was entered;
             *   - `assigned`: the set of variable names that received an Assign anywhere
             *     inside the branch body.
             *
             * On enter of a branching parent we push a frame. On entering a sibling
             * branch (else / elseif / case / catch / finally / match arm), we reset
             * `currentScopeLocalTypes` from the top frame's snapshot -- each sibling
             * starts from the pre-branch state, NOT from the previous sibling's
             * mutations. On leave of the branching parent, we pop the frame, restore
             * the snapshot, and INVALIDATE every variable in `assigned` (we can't
             * tell at runtime whether the branch ran or not -- conservative says
             * "we don't know the type"). The popped frame's `assigned` set
             * propagates into the parent frame so nested branches stay reflected
             * through the outer invalidation pass.
             *
             * Without this, `if ($cond) { $x = new Bar(); } $x->m::<T>()` silently
             * picks Bar (the last lexical write) regardless of whether the branch
             * fired -- the original bug review of the post-b88539c work flagged.
             *
             * `perBranchTypes` records the end-of-arm `currentScopeLocalTypes[$x]`
             * value (or `null` if untracked at arm end) for every variable in
             * `assigned`, one slot per arm visited so far. `armIndex` is the
             * 0-based index of the currently-active arm, starting at 0 for
             * `If_` (whose body is the first arm) and `-1` for `Switch_` /
             * `Match_` (whose parent body is the switch/match expression --
             * not an arm; the first `Case_` / `MatchArm` enter is the first
             * arm). On leave, if every captured slot agrees on the same FQN
             * AND the slot count matches the structural arm count, the merge
             * keeps `$x` instead of invalidating. See `P5.1-same-class-merge.md`
             * and the `computeMergedTypes` / `canMergeOnLeave` helpers below.
             *
             * @var list<array{snapshot: array<string,string>, localArgsSnapshot: array<string, list<TypeRef>>, assigned: array<string,bool>, perBranchTypes: list<array<string, ?string>>, perBranchArgs: list<array<string, ?list<TypeRef>>>, armIndex: int}>
             */
            private array $branchSnapshots = [];

            /**
             * @param array<string, true> $alreadyGenerated
             * @param list<Function_> $topLevelAppends
             */
            public function __construct(
                private readonly TemplateIndex $index,
                private array &$alreadyGenerated,
                private int $hashLength,
                private ?TypeHierarchy $hierarchy,
                public array &$topLevelAppends,
                private readonly ?DiagnosticCollector $diagnostics,
                private readonly string $currentFile,
                private readonly ?ClosureConformanceValidator $closureValidator,
            ) {
                $this->nsContext = new NamespaceContext();
            }

            /**
             * Reset the visitor's lexical state for one drained (detached) specialized
             * stmt. The stmt is traversed outside any Namespace_/Use_/ClassLike parent,
             * so enterNode never primes this state — left stale it would resolve names
             * against whatever file the main traversal last walked. The use-alias map is
             * cleared rather than reconstructed: names the drain needs are attribute-
             * resolved (ATTR_TEMPLATE_FQN / ATTR_RESOLVED_FQN at parse time), so aliases
             * are never consulted on the markers-only path.
             */
            public function primeDrainScope(?string $classFqn, string $namespace): void
            {
                $this->currentClassFqn = $classFqn;
                $this->currentNamespace = $namespace;
                $this->currentNamespaceNode = null;
                $this->useMap = [];
                $this->nsContext = new NamespaceContext();
                $this->nsContext->enterNamespace($namespace !== '' ? $namespace : null);
                $this->currentScopeParamTypes = [];
                $this->currentScopeLocalTypes = [];
                $this->currentScopeParamTypeArgs = [];
                $this->currentScopeLocalTypeArgs = [];
                $this->branchSnapshots = [];
                $this->scopeSnapshots = [];
                $this->currentScopeClosureTemplates = [];
                $this->currentScopeClosureContexts = [];
                $this->callReturnCache = [];
            }

            public function enterNode(Node $node): null|int
            {
                // A markers-only walk skips generic declarations still carrying their
                // template marker wholesale: in check mode nothing is stripped, so a
                // spec clone contains the generic-method templates themselves, whose
                // method-param-leaf markers the Phase-1a walk already validated —
                // re-walking them would duplicate diagnostics (or false-flag closure
                // templates the template walk already handled). leaveNode mirrors the
                // test so it never pops a scope this skip never pushed.
                if ($this->markersOnly && self::isUnspecializedTemplateDeclaration($node)) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    $this->currentNamespaceNode = $node;
                    $this->useMap = [];
                    $this->nsContext->enterNamespace($node->name?->toString());
                }
                if ($node instanceof Use_) {
                    foreach ($node->uses as $u) {
                        // @phpstan-ignore-next-line instanceof.alwaysTrue — defensive guard against nikic/php-parser PHPDoc-narrowed Use_::$uses (pre-5.x emitted UseUse, current emits UseItem).
                        if (!$u instanceof UseItem) {
                            continue;
                        }
                        $fqn = $u->name->toString();
                        $alias = $u->alias?->toString() ?? self::lastSegment($fqn);
                        $this->useMap[$alias] = $fqn;
                    }
                    $this->nsContext->indexUse($node);
                }
                if ($node instanceof GroupUse) {
                    // A group import (`use N\{A, B}`) must reach the same NamespaceContext so a
                    // group-imported class named in a closure-literal argument resolves to its
                    // real FQN, not a current-namespace collision that could false-reject.
                    $this->nsContext->indexGroupUse($node);
                }
                if ($node instanceof ClassLike && $node->name !== null) {
                    $this->currentClassFqn = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $node->name->toString()
                        : $node->name->toString();
                }
                if ($node instanceof Function_
                    || $node instanceof ClassMethod
                    || $node instanceof Closure
                    || $node instanceof ArrowFunction
                ) {
                    // Push outgoing scope (params + locals + branch stack) before
                    // computing the new one. The branch stack is per-scope: branches
                    // inside the closure are independent of branches in the parent.
                    $parentParams = $this->currentScopeParamTypes;
                    $parentLocals = $this->currentScopeLocalTypes;
                    $parentParamArgs = $this->currentScopeParamTypeArgs;
                    $parentLocalArgs = $this->currentScopeLocalTypeArgs;
                    $this->scopeSnapshots[] = [
                        'params' => $parentParams,
                        'locals' => $parentLocals,
                        'paramArgs' => $parentParamArgs,
                        'localArgs' => $parentLocalArgs,
                        'branches' => $this->branchSnapshots,
                        // Closure-template tracking is per-scope too: a generic closure assigned to `$f`
                        // in one function must NOT leak into a sibling scope where `$f` is an unrelated
                        // callable, or a bare `$f(...)` there would be misreported.
                        'closureTemplates' => $this->currentScopeClosureTemplates,
                        'closureContexts' => $this->currentScopeClosureContexts,
                    ];
                    $this->currentScopeParamTypes = [];
                    $this->currentScopeLocalTypes = [];
                    $this->currentScopeParamTypeArgs = [];
                    $this->currentScopeLocalTypeArgs = [];
                    $this->branchSnapshots = [];
                    $this->currentScopeClosureTemplates = [];
                    $this->currentScopeClosureContexts = [];

                    // For closures: `use ($x)` explicitly imports outer variables.
                    // Copy each imported name's type from the parent scope so the
                    // closure body can specialize `$x->m::<T>(...)` correctly.
                    if ($node instanceof Closure) {
                        foreach ($node->uses as $use) {
                            // @phpstan-ignore-next-line instanceof.alwaysTrue — defensive guard against nikic/php-parser PHPDoc-narrowed ClosureUse::$var.
                            if (!$use->var instanceof Variable || !is_string($use->var->name)) {
                                continue;
                            }
                            $importedName = $use->var->name;
                            $importedType = $parentParams[$importedName]
                                ?? $parentLocals[$importedName]
                                ?? null;
                            if ($importedType !== null) {
                                $this->currentScopeParamTypes[$importedName] = $importedType;
                            }
                            $importedArgs = $parentParamArgs[$importedName]
                                ?? $parentLocalArgs[$importedName]
                                ?? null;
                            if ($importedArgs !== null) {
                                $this->currentScopeParamTypeArgs[$importedName] = $importedArgs;
                            }
                        }
                    }

                    // Arrow functions implicitly capture every outer variable by
                    // value. Copy all of parent's tracked types so the single-
                    // expression body can specialize the same way the parent could.
                    if ($node instanceof ArrowFunction) {
                        foreach ($parentParams as $importedName => $importedType) {
                            $this->currentScopeParamTypes[$importedName] = $importedType;
                        }
                        foreach ($parentLocals as $importedName => $importedType) {
                            $this->currentScopeParamTypes[$importedName] = $importedType;
                        }
                        foreach ($parentParamArgs as $importedName => $importedArgs) {
                            $this->currentScopeParamTypeArgs[$importedName] = $importedArgs;
                        }
                        foreach ($parentLocalArgs as $importedName => $importedArgs) {
                            $this->currentScopeParamTypeArgs[$importedName] = $importedArgs;
                        }
                    }

                    // Declared parameter types overwrite any imported same-named
                    // outer variable -- the param shadows the outer in PHP semantics.
                    foreach ($node->params as $param) {
                        if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                            continue;
                        }
                        $type = $param->type;
                        // Strip nullable wrapper: `?Container` is still "the receiver is Container"
                        // for method-resolution purposes (the runtime null-check is the caller's
                        // problem, not the type-resolution step).
                        if ($type instanceof NullableType) {
                            $type = $type->type;
                        }
                        if ($type instanceof Name) {
                            $this->currentScopeParamTypes[$param->var->name] = $this->resolveClassName($type);
                            // Side-table the param's generic args (`Box<Product> $b` → [Product]) so a
                            // bound referencing the enclosing class param can be grounded. A shadowing
                            // param without generic args clears any imported stale entry.
                            $paramArgs = $type->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
                            if (is_array($paramArgs)) {
                                /** @var list<TypeRef> $paramArgs */
                                $this->currentScopeParamTypeArgs[$param->var->name] = $paramArgs;
                            } else {
                                unset($this->currentScopeParamTypeArgs[$param->var->name]);
                            }
                        }
                    }
                }
                // Branching parents: push a frame so any Assign inside the branch
                // body (or its sub-branches) gets recorded for post-leave
                // invalidation. The visitor enters each parent ONCE; siblings
                // (Else_/ElseIf_/Case_/Catch_/Finally_/MatchArm) reset
                // currentScopeLocalTypes from the top frame's snapshot.
                if (self::isBranchingParent($node)) {
                    $this->branchSnapshots[] = [
                        'snapshot' => $this->currentScopeLocalTypes,
                        'localArgsSnapshot' => $this->currentScopeLocalTypeArgs,
                        'assigned' => [],
                        'perBranchTypes' => [],
                        'perBranchArgs' => [],
                        // If_'s body is the first arm (armIndex=0).
                        // Switch_ / Match_ have no parent body arm; the first
                        // sibling enter promotes armIndex from -1 to 0.
                        // For loops + TryCatch the value doesn't matter
                        // (canMergeOnLeave returns false).
                        'armIndex' => ($node instanceof Switch_ || $node instanceof Match_) ? -1 : 0,
                    ];
                }
                if (self::isSiblingBranch($node) && $this->branchSnapshots !== []) {
                    $top = count($this->branchSnapshots) - 1;
                    // If a prior arm was active (armIndex >= 0), capture its
                    // end-state before resetting for the new arm. The
                    // armIndex == -1 case is the first Case_/MatchArm enter
                    // on Switch_/Match_, where no prior arm existed.
                    if ($this->branchSnapshots[$top]['armIndex'] >= 0) {
                        $this->branchSnapshots[$top]['perBranchTypes'][] =
                            self::captureArmTypes(
                                $this->branchSnapshots[$top]['assigned'],
                                $this->currentScopeLocalTypes,
                            );
                        $this->branchSnapshots[$top]['perBranchArgs'][] =
                            self::captureArmArgs(
                                $this->branchSnapshots[$top]['assigned'],
                                $this->currentScopeLocalTypeArgs,
                            );
                    }
                    $this->branchSnapshots[$top]['armIndex']++;
                    $this->currentScopeLocalTypes = $this->branchSnapshots[$top]['snapshot'];
                    // Args track the string map: each arm restarts from the pre-branch snapshot, so a
                    // var an earlier arm set can't leak its args into a sibling arm.
                    $this->currentScopeLocalTypeArgs = $this->branchSnapshots[$top]['localArgsSnapshot'];
                }
                // Stage B flow typing: `$x = new ClassName(...)` records `$x`'s receiver
                // type for later MethodCall sites in the same scope. Lexical last-write
                // wins within a straight-line code path; branching constructs invalidate
                // their assigned vars on leave (see branchSnapshots above).
                if ($node instanceof Assign
                    && $node->var instanceof Variable
                    && is_string($node->var->name)
                ) {
                    $assignedName = $node->var->name;
                    // Record the assignment in the innermost active branch frame
                    // regardless of RHS shape -- even a non-`new` assign poisons
                    // our tracked type for the post-leave invalidation.
                    if ($this->branchSnapshots !== []) {
                        $top = count($this->branchSnapshots) - 1;
                        $this->branchSnapshots[$top]['assigned'][$assignedName] = true;
                    }
                    // A reassignment invalidates the DECLARED parameter type for this name:
                    // after `$x = …` the variable no longer holds its incoming parameter type,
                    // so the param entry must stop masking the live local tracking below
                    // (resolveReceiverFqn / resolveReceiverTypeArgs read param ?? local). The
                    // new type is re-derived from the RHS in the chain that follows, or dropped
                    // when the RHS is untrackable — never left as the stale declared type.
                    unset(
                        $this->currentScopeParamTypes[$assignedName],
                        $this->currentScopeParamTypeArgs[$assignedName],
                    );
                    // Update the live tracked type only when the RHS is `new ClassName(...)`
                    // -- that's the one shape we can prove statically. Any other RHS is
                    // untrackable and clears the slot (see the closing `else`).
                    if ($node->expr instanceof New_
                        && $node->expr->class instanceof Name
                    ) {
                        $this->currentScopeLocalTypes[$assignedName] = $this->resolveClassName($node->expr->class);
                        // Side-table the constructed type's generic args (`new Box::<Product>()` →
                        // [Product]); a non-generic `new` clears any stale args from a prior write.
                        $newArgs = $node->expr->class->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
                        if (is_array($newArgs)) {
                            /** @var list<TypeRef> $newArgs */
                            $this->currentScopeLocalTypeArgs[$assignedName] = $newArgs;
                        } else {
                            unset($this->currentScopeLocalTypeArgs[$assignedName]);
                        }
                    } elseif ($node->expr instanceof MethodCall
                        || $node->expr instanceof NullsafeMethodCall
                        || $node->expr instanceof StaticCall
                    ) {
                        // `$x = $repo->find()` / `$x = $this->getBox()`: track the call's declared
                        // return type so a later `$x->m::<…>()` is grounded. An unresolvable call
                        // clears any stale tracked type rather than leaving a wrong one in place.
                        $return = $this->resolveCallReturn($node->expr);
                        if ($return === null) {
                            unset(
                                $this->currentScopeLocalTypes[$assignedName],
                                $this->currentScopeLocalTypeArgs[$assignedName],
                            );
                        } else {
                            $this->currentScopeLocalTypes[$assignedName] = $return[0];
                            if ($return[1] !== []) {
                                $this->currentScopeLocalTypeArgs[$assignedName] = $return[1];
                            } else {
                                unset($this->currentScopeLocalTypeArgs[$assignedName]);
                            }
                        }
                    } else {
                        // Any other RHS (a free-function call, another variable, a ternary,
                        // an array/property fetch, a closure literal) is untrackable — drop
                        // any stale tracked type rather than leave a wrong one that a later
                        // `$x->m(...)` would mis-resolve against.
                        unset(
                            $this->currentScopeLocalTypes[$assignedName],
                            $this->currentScopeLocalTypeArgs[$assignedName],
                        );
                    }
                    // Track anonymous generic templates: `$id = fn<T>(T $x) => $x`
                    // or `$id = function<T>(T $x): T { ... }`. The FuncCall-on-
                    // Variable rewriter looks the variable up to find the
                    // template body for `$id::<int>(...)` call-site
                    // specialization.
                    if (($node->expr instanceof Closure
                            || $node->expr instanceof ArrowFunction)
                        && is_array($node->expr->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS))
                    ) {
                        $this->currentScopeClosureTemplates[$assignedName] = $node->expr;
                        $this->currentScopeClosureContexts[$assignedName] = [
                            'assign'        => $node,
                            'namespace'     => $this->currentNamespace,
                            'namespaceNode' => $this->currentNamespaceNode,
                        ];
                    }
                }
                return null;
            }

            public function leaveNode(Node $node): ?Node
            {
                if ($node instanceof StaticCall) {
                    if ($this->markersOnly) {
                        // Phase-1a drain (no grounding context): static resolution is
                        // not drain-safe there; a kept marker falls to the leak guard
                        // exactly as it did before the drain existed. Grounding a
                        // specialized class DOES process static markers — but only
                        // marker-bearing ones, and never the `static::`/`parent::`
                        // spellings: `resolveClassName` maps both to the current class,
                        // which would silently mis-ground a late-bound or parent-side
                        // dispatch; keeping the marker fails loudly instead.
                        if ($this->grounding === null
                            || $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS) === null
                            || $this->isLateBoundPseudoName($node->class)
                        ) {
                            return null;
                        }
                    }
                    return $this->rewriteStaticCall($node);
                }
                if ($node instanceof FuncCall) {
                    // Drain traversals rewrite ONLY named-call turbofish markers: dispatch
                    // is ATTR_TEMPLATE_FQN-driven (no lexical resolution), so a detached
                    // body grounds safely. Bare calls and variable turbofish (`$f::<...>`)
                    // are skipped — dispatcher finalize has already run, and a surviving
                    // variable marker stays for the leak guard's closure arm.
                    if ($this->markersOnly
                        && (!$node->name instanceof Name
                            || $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS) === null)
                    ) {
                        return null;
                    }
                    return $this->rewriteFuncCall($node);
                }
                if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall) {
                    // Same as StaticCall: instance-marker grounding inside drained bodies
                    // is not supported here; the marker survives for the leak guard.
                    if ($this->markersOnly) {
                        return null;
                    }
                    return $this->rewriteInstanceMethodCall($node);
                }
                if ($node instanceof ClassLike) {
                    $this->currentClassFqn = null;
                }
                // Mirror of enterNode's markers-only template skip: the enter never
                // pushed a scope for this declaration, so the pop below must not run.
                if ($this->markersOnly && self::isUnspecializedTemplateDeclaration($node)) {
                    return null;
                }
                if ($node instanceof Function_
                    || $node instanceof ClassMethod
                    || $node instanceof Closure
                    || $node instanceof ArrowFunction
                ) {
                    $snapshot = array_pop($this->scopeSnapshots);
                    if ($snapshot !== null) {
                        $this->currentScopeParamTypes = $snapshot['params'];
                        $this->currentScopeLocalTypes = $snapshot['locals'];
                        $this->currentScopeParamTypeArgs = $snapshot['paramArgs'];
                        $this->currentScopeLocalTypeArgs = $snapshot['localArgs'];
                        $this->branchSnapshots = $snapshot['branches'];
                        $this->currentScopeClosureTemplates = $snapshot['closureTemplates'];
                        $this->currentScopeClosureContexts = $snapshot['closureContexts'];
                    } else {
                        // Defensive: matched enter/leave count is invariant of the
                        // NodeTraverser; the else-branch is only reachable if the AST
                        // is malformed. Fall back to empty scope to avoid an undefined
                        // pop on the next leave.
                        $this->currentScopeParamTypes = [];
                        $this->currentScopeLocalTypes = [];
                        $this->currentScopeParamTypeArgs = [];
                        $this->currentScopeLocalTypeArgs = [];
                        $this->branchSnapshots = [];
                        $this->currentScopeClosureTemplates = [];
                        $this->currentScopeClosureContexts = [];
                    }
                }
                // Branching parents: pop the frame, restore the pre-branch local
                // types, then invalidate every variable that received an Assign
                // anywhere inside the branch body. Propagate the popped frame's
                // assigned set into the parent frame so nested branches contribute
                // to the outer invalidation pass.
                if (self::isBranchingParent($node)) {
                    $popped = array_pop($this->branchSnapshots);
                    if ($popped !== null) {
                        // Capture the final arm's end-state (symmetric with
                        // the per-sibling-enter capture above). Only when
                        // armIndex >= 0 -- a Switch_/Match_ with zero
                        // cases/arms would leave armIndex at -1.
                        if ($popped['armIndex'] >= 0) {
                            $popped['perBranchTypes'][] = self::captureArmTypes(
                                $popped['assigned'],
                                $this->currentScopeLocalTypes,
                            );
                            $popped['perBranchArgs'][] = self::captureArmArgs(
                                $popped['assigned'],
                                $this->currentScopeLocalTypeArgs,
                            );
                        }

                        // Restore to pre-branch state.
                        $this->currentScopeLocalTypes = $popped['snapshot'];
                        $this->currentScopeLocalTypeArgs = $popped['localArgsSnapshot'];

                        // P5.1 same-class merge: try to keep variables whose
                        // every reachable arm assigned the same FQN, instead
                        // of unconditionally invalidating below.
                        $merged = self::computeMergedTypes($node, $popped);
                        $mergedArgs = self::computeMergedArgs($node, $popped);

                        foreach ($popped['assigned'] as $assignedName => $_true) {
                            if (isset($merged[$assignedName])) {
                                $this->currentScopeLocalTypes[$assignedName] = $merged[$assignedName];
                            } else {
                                unset($this->currentScopeLocalTypes[$assignedName]);
                            }
                            // Keep the receiver's type args only when the class merged AND every arm
                            // agreed on the args (by canonical()); a differing-arm receiver
                            // (Box<Fruit> vs Box<Banana>) drops its args and stays undeterminable.
                            if (isset($merged[$assignedName], $mergedArgs[$assignedName])) {
                                $this->currentScopeLocalTypeArgs[$assignedName] = $mergedArgs[$assignedName];
                            } else {
                                unset($this->currentScopeLocalTypeArgs[$assignedName]);
                            }
                            if ($this->branchSnapshots !== []) {
                                $parentTop = count($this->branchSnapshots) - 1;
                                $this->branchSnapshots[$parentTop]['assigned'][$assignedName] = true;
                            }
                        }
                    }
                }
                return null;
            }

            /**
             * Branching parents push a fresh frame on enter and pop on leave. These
             * are the control-flow constructs whose body MAY OR MAY NOT execute (or
             * may execute MULTIPLE TIMES, in the case of loops). Either way, the
             * receiver-type tracker can't rely on the body's assignments to hold
             * post-leave.
             */
            private static function isBranchingParent(Node $node): bool
            {
                return $node instanceof If_
                    || $node instanceof Switch_
                    || $node instanceof Match_
                    || $node instanceof While_
                    || $node instanceof Do_
                    || $node instanceof For_
                    || $node instanceof Foreach_
                    || $node instanceof TryCatch;
            }

            /**
             * Sibling-branch nodes (Else_, ElseIf_, the cases of Switch_, the arms
             * of Match_, Catch_/Finally_ on TryCatch). Each sibling starts from the
             * pre-branch state -- without the reset, the else body would see the
             * if body's mutations and pick the wrong receiver class.
             */
            private static function isSiblingBranch(Node $node): bool
            {
                return $node instanceof Else_
                    || $node instanceof ElseIf_
                    || $node instanceof Case_
                    || $node instanceof MatchArm
                    || $node instanceof Catch_
                    || $node instanceof Finally_;
            }

            /**
             * Snapshot the end-of-arm state of every name in the frame's
             * `assigned` set. Returns a map name -> ?FQN where `null` means
             * "this arm did not finish with a tracked FQN for that name".
             *
             * @param array<string, bool> $assigned
             * @param array<string, string> $currentTypes
             * @return array<string, ?string>
             */
            private static function captureArmTypes(array $assigned, array $currentTypes): array
            {
                $out = [];
                foreach ($assigned as $name => $_true) {
                    $out[$name] = $currentTypes[$name] ?? null;
                }
                return $out;
            }

            /**
             * Per-arm receiver type args for the `assigned` set, parallel to captureArmTypes.
             * `null` means "this arm did not finish with tracked args for that name".
             *
             * @param array<string, bool> $assigned
             * @param array<string, list<TypeRef>> $currentArgs
             * @return array<string, ?list<TypeRef>>
             */
            private static function captureArmArgs(array $assigned, array $currentArgs): array
            {
                $out = [];
                foreach ($assigned as $name => $_true) {
                    $out[$name] = $currentArgs[$name] ?? null;
                }
                return $out;
            }

            /**
             * Same-class merge eligibility: only the all-arms-reachable
             * branching parents can participate. Loops always have an
             * implicit zero-iteration path; `if` without `else` has an
             * implicit empty path; switch without `default` and match
             * without `default` likewise. TryCatch is conservatively never
             * merged (the exception-not-thrown case is implicit).
             */
            private static function canMergeOnLeave(Node $node): bool
            {
                if ($node instanceof If_) {
                    return $node->else !== null;
                }
                if ($node instanceof Switch_) {
                    foreach ($node->cases as $case) {
                        if ($case->cond === null) {
                            return true;
                        }
                    }
                    return false;
                }
                if ($node instanceof Match_) {
                    foreach ($node->arms as $arm) {
                        if ($arm->conds === null) {
                            return true;
                        }
                    }
                    return false;
                }
                return false;
            }

            /**
             * Structural arm count for the merge guard. Only called when
             * `canMergeOnLeave($node)` is true, so `If_` is guaranteed to
             * have an `else`.
             *
             *   If_:     2 + count(elseifs)            (if-body + else + each elseif)
             *   Switch_: count(cases)                  (default already guaranteed)
             *   Match_:  count(arms)                   (default already guaranteed)
             */
            private static function expectedArmCount(Node $node): int
            {
                if ($node instanceof If_) {
                    return 2 + count($node->elseifs);
                }
                if ($node instanceof Switch_) {
                    return count($node->cases);
                }
                if ($node instanceof Match_) {
                    return count($node->arms);
                }
                return 0;
            }

            /**
             * Walk the captured per-arm types and return name -> FQN for
             * every name whose every arm ended with the same FQN. Returns
             * an empty map when the merge isn't allowed (canMergeOnLeave
             * false) or when the visited arm count doesn't match the
             * structural arm count (implicit empty arm).
             *
             * @param array{snapshot: array<string,string>, assigned: array<string,bool>, perBranchTypes: list<array<string, ?string>>} $popped
             * @return array<string, string>
             */
            private static function computeMergedTypes(Node $node, array $popped): array
            {
                if (!self::canMergeOnLeave($node)) {
                    return [];
                }
                $expected = self::expectedArmCount($node);
                if (count($popped['perBranchTypes']) !== $expected) {
                    return [];
                }
                $merged = [];
                foreach ($popped['assigned'] as $name => $_true) {
                    $firstType = null;
                    $allAgree = true;
                    foreach ($popped['perBranchTypes'] as $i => $armTypes) {
                        $type = $armTypes[$name] ?? null;
                        if ($type === null) {
                            $allAgree = false;
                            break;
                        }
                        if ($i === 0) {
                            $firstType = $type;
                            continue;
                        }
                        if ($type !== $firstType) {
                            $allAgree = false;
                            break;
                        }
                    }
                    if ($allAgree && $firstType !== null) {
                        $merged[$name] = $firstType;
                    }
                }
                return $merged;
            }

            /**
             * Same as computeMergedTypes, but for the receiver type args: name -> args for every var
             * whose every reachable arm ended with the SAME args (compared by `TypeRef::canonical()`).
             * Empty when the merge isn't allowed or an arm is missing args for the name. The caller
             * additionally gates on the FQN having merged, so args are kept only for a fully-agreed
             * receiver.
             *
             * @param array{perBranchArgs: list<array<string, ?list<TypeRef>>>, assigned: array<string,bool>} $popped
             * @return array<string, list<TypeRef>>
             */
            private static function computeMergedArgs(Node $node, array $popped): array
            {
                if (!self::canMergeOnLeave($node)) {
                    return [];
                }
                if (count($popped['perBranchArgs']) !== self::expectedArmCount($node)) {
                    return [];
                }
                $merged = [];
                foreach ($popped['assigned'] as $name => $_true) {
                    $firstKey = null;
                    /** @var list<TypeRef>|null $firstArgs */
                    $firstArgs = null;
                    $allAgree = true;
                    foreach ($popped['perBranchArgs'] as $i => $armArgs) {
                        $args = $armArgs[$name] ?? null;
                        if ($args === null) {
                            $allAgree = false;
                            break;
                        }
                        $key = implode(',', array_map(static fn (TypeRef $a): string => $a->canonical(), $args));
                        if ($i === 0) {
                            $firstKey = $key;
                            $firstArgs = $args;
                            continue;
                        }
                        if ($key !== $firstKey) {
                            $allAgree = false;
                            break;
                        }
                    }
                    if ($allAgree && $firstArgs !== null) {
                        $merged[$name] = $firstArgs;
                    }
                }
                return $merged;
            }

            private function rewriteStaticCall(StaticCall $node): ?Node
            {
                $args = $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
                if (!$node->name instanceof Identifier) {
                    return null;
                }
                if (!$node->class instanceof Name) {
                    return null;
                }

                // A drained/grounding walk runs over a DETACHED body: lexical use-alias
                // state is unavailable, so prefer the parse-time resolution attribute
                // (`self` carries none and still routes through resolveClassName, whose
                // currentClassFqn was primed per item). The normal Phase-1a walk keeps
                // its lexical resolution byte-identical.
                $resolvedAttr = $node->class->getAttribute(XphpSourceParser::ATTR_RESOLVED_FQN);
                $classFqn = $this->markersOnly && is_string($resolvedAttr)
                    ? $resolvedAttr
                    : $this->resolveClassName($node->class);
                $methodName = $node->name->toString();
                $key = $classFqn . '::' . $methodName;
                // Resolve through the inheritance chain (same as the instance path):
                // a static generic method declared on a base is callable as
                // `Sub::m::<...>()` and resolves via static-method inheritance.
                $resolved = $this->resolveMethodTemplate($classFqn, $methodName);
                if ($resolved === null) {
                    // Non-generic static method (no generic template): a non-turbofish call
                    // still checks its closure-literal arguments. A turbofish here is an
                    // unresolved-generic-call error, handled below.
                    if (!is_array($args)) {
                        $this->checkPlainStaticClosureArgs($node, $classFqn, $methodName);
                    }
                    return $this->reportUnresolvedTurbofishOrSkip($classFqn, $methodName, $node);
                }
                [$template, $declaringFqn] = $resolved;
                // Grounding mode: only the spec's OWN template's members may land on the
                // spec. A static target declared on a *different* generic template would
                // need that other template's substitution mapping (and mutating a shared
                // template mid-loop is order-dependent wrong code) — keep the marker and
                // let the emit backstop reject it as a documented limitation.
                if ($this->grounding !== null
                    && $declaringFqn !== $this->grounding->templateFqn
                    && $this->isGenericTemplateClass($declaringFqn)
                ) {
                    return null;
                }
                $params = $template->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                if (!is_array($params)) {
                    return null;
                }
                /** @var list<TypeParam> $params — set as a list by XphpSourceParser::resolveAndAttach. */
                // Bare call (no `::<...>`) on a generic method with all
                // defaults: pad to []. Already-tagged turbofish calls go
                // through padArgsWithDefaults too so partial-arg shapes are
                // filled in the same way class-level instantiations are.
                if (!is_array($args)) {
                    // A first-class-callable (`$obj->m(...)` / `Box::m(...)`) creates a Closure rather
                    // than invoking, so leave it alone.
                    if ($node->isFirstClassCallable()) {
                        return null;
                    }
                    // Bare call (no turbofish): fall through to padArgsWithDefaults, which pads an
                    // all-defaults generic and reports/throws `xphp.missing_type_argument` otherwise. A
                    // method generic can't infer its type argument from the call args, so a bare call to a
                    // non-all-default generic is an error — not a silent skip that emits a call to the
                    // stripped method and fatals at runtime.
                    $args = [];
                }
                /** @var list<TypeRef> $args — set as a list by XphpSourceParser::resolveAndAttach (or empty after the all-defaults branch above). */
                $location = new SourceLocation($this->currentFile, $node->getStartLine());
                $padded = Registry::padArgsWithDefaults($params, $args, $key, $this->diagnostics, $location);
                if (!self::allConcrete($padded) || count($params) !== count($padded)) {
                    return null;
                }
                $args = $padded;

                if ($this->hierarchy !== null) {
                    // A class type parameter is unbound in a static context (no instance to ground
                    // `E`), so pass no receiver args and never defer: a class-param bound on a static
                    // method is genuinely unprovable and fails. The call's own turbofish args are
                    // still threaded, so a method-own sibling bound (`<U, V : U>`) is grounded.
                    $checkedParams = $this->groundBounds(
                        $params,
                        $args,
                        $classFqn,
                        [],
                        $declaringFqn,
                        false,
                        $classFqn . '::' . $methodName,
                        $location,
                    );
                    Registry::checkBounds(
                        $checkedParams,
                        $args,
                        $this->hierarchy,
                        $classFqn . '::' . $methodName . '<' . self::formatArgList($args) . '>',
                        $this->diagnostics,
                        $location,
                    );

                    // Check each closure-literal call argument against its paired parameter's
                    // Closure(...) target. In a static context a class type parameter is unbound
                    // (no receiver to ground `E`), so the substitution carries only this call's
                    // method-type arguments — a sig leaf referencing a class parameter stays
                    // abstract ⇒ gradual, matching how bounds degrade here.
                    if ($this->closureValidator !== null) {
                        $overlay = [];
                        foreach ($params as $i => $param) {
                            $overlay[$param->name] = $args[$i];
                        }
                        $this->closureValidator->checkCallArguments(
                            array_values($template->params),
                            $node->args,
                            Substitution::of($overlay),
                            $this->nsContext,
                            $this->currentFile,
                            $this->diagnostics,
                        );
                    }
                }

                $mangled = self::mangleName($methodName, $args, $this->hashLength);

                // Grounding a spec whose OWN template declares the target: the member
                // lands on the spec itself — the template class lowers to a marker
                // interface in output, so an append there would vanish (and mutating a
                // shared template mid-loop would be order-dependent). Dedup per
                // specialization, but consult the global key first: a member a concrete
                // Phase-1a call already appended onto the template was cloned INTO this
                // spec, and appending again would redeclare the method (load-time fatal).
                if ($this->grounding !== null && $declaringFqn === $this->grounding->templateFqn) {
                    $templateKey = $declaringFqn . '::' . $mangled;
                    $specKey = $this->grounding->generatedFqn . '::' . $mangled;
                    if (!isset($this->alreadyGenerated[$templateKey]) && !isset($this->alreadyGenerated[$specKey])) {
                        // Compose the enclosing class substitution under the method's
                        // own overlay: the detached template's body may reference class
                        // type parameters, which are concrete for THIS spec only.
                        $overlay = $this->groundingClassOverlay();
                        foreach ($params as $i => $param) {
                            $overlay[$param->name] = $args[$i];
                        }
                        $specialized = (new Specializer())->specializeMethod($template, Substitution::of($overlay), $mangled);
                        $this->pendingAppends[] = [$this->grounding->spec, $specialized, [
                            'classFqn'  => $declaringFqn,
                            'namespace' => self::namespaceOf($declaringFqn),
                        ]];
                        $this->alreadyGenerated[$specKey] = true;
                    }
                    $node->name = new Identifier($mangled, $node->name->getAttributes());
                    $node->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS, null);
                    // Dispatch through `self`: the member lives on the emitted spec, and
                    // the template FQN spelling (`Box::gen` / `\App\Box::gen`) would
                    // resolve to the stripped marker interface.
                    $node->class = new Name('self', $node->class->getAttributes());
                    return $node;
                }

                // Emit onto the declaring class (see the instance path) so subclasses
                // inherit the single specialization; dedup by the declaring FQN.
                $generatedKey = $declaringFqn . '::' . $mangled;
                if (!isset($this->alreadyGenerated[$generatedKey])) {
                    $overlay = [];
                    foreach ($params as $i => $param) {
                        $overlay[$param->name] = $args[$i];
                    }
                    $specialized = (new Specializer())->specializeMethod($template, Substitution::of($overlay), $mangled);
                    $owner = $this->index->classLike($declaringFqn);
                    if ($owner !== null) {
                        // Buffer the append (see rewriteFuncCall for the rationale).
                        $this->pendingAppends[] = [$owner, $specialized, [
                            'classFqn'  => $declaringFqn,
                            'namespace' => self::namespaceOf($declaringFqn),
                        ]];
                        $this->alreadyGenerated[$generatedKey] = true;
                    }
                }

                $node->name = new Identifier($mangled, $node->name->getAttributes());
                $node->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS, null);

                if (!$node->class instanceof FullyQualified) {
                    $node->class = new FullyQualified($classFqn, $node->class->getAttributes());
                }

                return $node;
            }

            /**
             * Instance-method turbofish rewrite. Same mangling / append shape as the
             * StaticCall path; the only new piece is `resolveReceiverFqn` -- the
             * receiver-type analysis that says "this $obj is statically of type X" so
             * we can pick the right method template from $methodTemplates.
             *
             * Stage A coverage (this commit):
             *   - `$this->method::<T>(...)` -- receiver is the enclosing class.
             *   - `$param->method::<T>(...)` -- receiver is the function/method
             *     parameter's declared type (snapshot in $currentScopeParamTypes).
             *
             * When the receiver's type can't be resolved, a *turbofish* call can't be
             * specialized — the generic method only exists as mangled specializations,
             * so leaving it would emit a call to a method that doesn't exist and fatal
             * at runtime. Ground-or-fail: report it at compile time (see
             * `reportUndeterminedReceiverOrSkip`). An ordinary (non-turbofish) call on
             * an unresolved receiver is none of our business and passes through.
             */
            private function rewriteInstanceMethodCall(MethodCall|NullsafeMethodCall $node): ?Node
            {
                $args = $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
                if (!$node->name instanceof Identifier) {
                    return null;
                }

                $classFqn = $this->resolveReceiverFqn($node->var);
                if ($classFqn === null) {
                    return $this->reportUndeterminedReceiverOrSkip($node->name->toString(), $node);
                }
                $methodName = $node->name->toString();
                $key = $classFqn . '::' . $methodName;
                // Resolve through the inheritance chain: a generic method declared on
                // a base class is callable on a subclass receiver. $declaringFqn is
                // where the template actually lives, so the specialization is emitted
                // there and inherited (see resolveMethodTemplate).
                $resolved = $this->resolveMethodTemplate($classFqn, $methodName);
                if ($resolved === null) {
                    // Non-generic method (no generic template): a non-turbofish call still
                    // checks its closure-literal arguments. A turbofish here is an
                    // unresolved-generic-call error, handled below.
                    if (!is_array($args)) {
                        $this->checkPlainInstanceClosureArgs($node, $classFqn, $methodName);
                    }
                    return $this->reportUnresolvedTurbofishOrSkip($classFqn, $methodName, $node);
                }
                [$template, $declaringFqn] = $resolved;
                $params = $template->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                if (!is_array($params)) {
                    return null;
                }
                /** @var list<TypeParam> $params — set as a list by XphpSourceParser::resolveAndAttach. */
                if (!is_array($args)) {
                    // A first-class-callable (`$obj->m(...)` / `Box::m(...)`) creates a Closure rather
                    // than invoking, so leave it alone.
                    if ($node->isFirstClassCallable()) {
                        return null;
                    }
                    // Bare call (no turbofish): fall through to padArgsWithDefaults, which pads an
                    // all-defaults generic and reports/throws `xphp.missing_type_argument` otherwise. A
                    // method generic can't infer its type argument from the call args, so a bare call to a
                    // non-all-default generic is an error — not a silent skip that emits a call to the
                    // stripped method and fatals at runtime.
                    $args = [];
                }
                /** @var list<TypeRef> $args — set as a list by XphpSourceParser::resolveAndAttach (or empty after the all-defaults branch above). */
                $location = new SourceLocation($this->currentFile, $node->getStartLine());
                $padded = Registry::padArgsWithDefaults($params, $args, $key, $this->diagnostics, $location);
                // Arity first: in `check` mode padArgsWithDefaults collects an arity diagnostic and
                // returns the (still mis-sized) args, so an arity problem must short-circuit here
                // before the concreteness check — otherwise a too-many/missing-arg self-call would
                // also draw a spurious `unspecializable_self_call` on top of the real arity error.
                if (count($params) !== count($padded)) {
                    return null;
                }
                if (!self::allConcrete($padded)) {
                    // A non-concrete turbofish arg is an abstract type parameter forwarded from the
                    // enclosing generic method (`probe<U:E>{ $this->contains::<U>(...) }`). On a
                    // `$this`-rooted receiver this can't be specialized at the template — the arg is
                    // concrete only per instantiation. When the target is ERASABLE the Specializer
                    // rewrites this self-call to the target's E-mangled name per instantiation, so it
                    // resolves; leave it for that pass. Otherwise it would emit a bare `$this->m(...)`
                    // to a method that was never specialized (a runtime fatal) — so report it.
                    if ($this->receiverRootedAtThis($node->var)
                        && !$this->isErasableTarget($template, $params, $declaringFqn)
                    ) {
                        return $this->reportUnspecializableSelfCall($methodName, $location);
                    }
                    return null;
                }
                $args = $padded;

                if ($this->hierarchy !== null) {
                    // Ground a method-generic bound that references an enclosing class type
                    // parameter (`<U : E>`) against the receiver's concrete arguments, threaded
                    // to the method's declaring class. An ungroundable bound is a compile error; the
                    // `$this`-rooted flag only tailors the diagnostic's remedy (a self-call can't
                    // bind to a typed local), it does not suppress the failure.
                    $receiverArgs = $this->resolveReceiverTypeArgs($node->var);
                    $checkedParams = $this->groundBounds(
                        $params,
                        $args,
                        $classFqn,
                        $receiverArgs,
                        $declaringFqn,
                        $this->receiverRootedAtThis($node->var),
                        $classFqn . '::' . $methodName,
                        $location,
                    );
                    Registry::checkBounds(
                        $checkedParams,
                        $args,
                        $this->hierarchy,
                        $classFqn . '::' . $methodName . '<' . self::formatArgList($args) . '>',
                        $this->diagnostics,
                        $location,
                    );

                    // Check each closure-literal call argument against its paired parameter's
                    // Closure(...) target, grounded through the receiver's class type args and
                    // this call's method-type arguments. Method params are layered LAST so a
                    // method type parameter shadows a same-named class one (matching groundBounds
                    // and PHP's inner-scope-wins rule). Runs before the erasable early-return so
                    // `<U:E>` methods are still argument-checked, and outside the alreadyGenerated
                    // dedup so every call site is checked — not only the one that specializes.
                    if ($this->closureValidator !== null) {
                        $overlay = [];
                        foreach ($params as $i => $param) {
                            $overlay[$param->name] = $args[$i];
                        }
                        $subst = $this->classSubstitutionFor($classFqn, $receiverArgs, $declaringFqn)
                            ->withOverrides(Substitution::of($overlay));
                        $this->closureValidator->checkCallArguments(
                            array_values($template->params),
                            $node->args,
                            $subst,
                            $this->nsContext,
                            $this->currentFile,
                            $this->diagnostics,
                        );
                    }

                    // Erasable `<U : E>` method: the bound is checked above, but the call lowers to
                    // the E-mangled name keyed on the RECEIVER's element type (not the turbofish arg),
                    // and the member is emitted by the Specializer per class instantiation — so there
                    // is no per-call append here. Both sides key on EnclosingBoundErasure::mangleArgs,
                    // producing the same name.
                    $declaringClass = $this->index->classLike($declaringFqn);
                    $classParams = $declaringClass?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                    if (is_array($classParams)) {
                        /** @var list<TypeParam> $classParams */
                        $classParamNames = array_map(static fn (TypeParam $p): string => $p->name, $classParams);
                        if (EnclosingBoundErasure::isErasable($template, $params, $classParamNames)) {
                            $classConcrete = $this->classSubstitutionFor($classFqn, $receiverArgs, $declaringFqn);
                            if (!$classConcrete->isEmpty()) {
                                $erased = self::mangleName(
                                    $methodName,
                                    EnclosingBoundErasure::mangleArgs($params, $classConcrete),
                                    $this->hashLength,
                                );
                                $node->name = new Identifier($erased, $node->name->getAttributes());
                                $node->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS, null);
                                return $node;
                            }
                        }
                    }
                }

                $mangled = self::mangleName($methodName, $args, $this->hashLength);
                // Key emission + dedup by the DECLARING class, not the receiver: the
                // specialization lands on the base and every subclass inherits the one
                // copy. Keying by receiver would append a duplicate per subclass.
                $generatedKey = $declaringFqn . '::' . $mangled;
                if (!isset($this->alreadyGenerated[$generatedKey])) {
                    $overlay = [];
                    foreach ($params as $i => $param) {
                        $overlay[$param->name] = $args[$i];
                    }
                    $specialized = (new Specializer())->specializeMethod($template, Substitution::of($overlay), $mangled);
                    $owner = $this->index->classLike($declaringFqn);
                    if ($owner !== null) {
                        $this->pendingAppends[] = [$owner, $specialized, [
                            'classFqn'  => $declaringFqn,
                            'namespace' => self::namespaceOf($declaringFqn),
                        ]];
                        $this->alreadyGenerated[$generatedKey] = true;
                    }
                }

                $node->name = new Identifier($mangled, $node->name->getAttributes());
                $node->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS, null);

                return $node;
            }

            /**
             * Resolve a generic-method template by receiver FQN, walking up the
             * inheritance chain when the method is declared on an ancestor.
             *
             * Returns the template paired with the FQN of the class that actually
             * declares it -- the specialization is emitted onto that declaring class
             * so every subclass inherits it through the existing class-level extends
             * edge. A direct hit on the receiver's own class wins over the ancestor
             * walk (a subclass override shadows an inherited method). Returns null
             * when neither the receiver nor any ancestor declares the method.
             *
             * @return array{0: ClassMethod, 1: string}|null  [template, declaringFqn]
             */
            private function resolveMethodTemplate(string $receiverFqn, string $methodName): ?array
            {
                $direct = $this->index->methodTemplate($receiverFqn, $methodName);
                if ($direct !== null) {
                    return [$direct, $receiverFqn];
                }
                if ($this->hierarchy === null) {
                    return null;
                }
                foreach ($this->hierarchy->ancestorChain($receiverFqn) as $ancestorFqn) {
                    $inherited = $this->index->methodTemplate($ancestorFqn, $methodName);
                    if ($inherited !== null) {
                        return [$inherited, $ancestorFqn];
                    }
                }
                return null;
            }

            /**
             * Find any method declaration (generic OR not) by receiver FQN, walking the inheritance
             * chain for an inherited declaration. Unlike {@see resolveMethodTemplate} this reads the
             * full class AST, so a plain getter whose return type is what we want to track is found.
             *
             * @return array{0: ClassMethod, 1: string}|null  [method, declaringFqn]
             */
            private function findMethodDeclaration(string $receiverFqn, string $methodName): ?array
            {
                $direct = $this->findMethodOn($receiverFqn, $methodName);
                if ($direct !== null) {
                    return [$direct, $receiverFqn];
                }
                if ($this->hierarchy === null) {
                    return null;
                }
                foreach ($this->hierarchy->ancestorChain($receiverFqn) as $ancestorFqn) {
                    $inherited = $this->findMethodOn($ancestorFqn, $methodName);
                    if ($inherited !== null) {
                        return [$inherited, $ancestorFqn];
                    }
                }
                return null;
            }

            private function findMethodOn(string $classFqn, string $methodName): ?ClassMethod
            {
                $owner = $this->index->classLike($classFqn);
                if ($owner === null) {
                    return null;
                }
                foreach ($owner->stmts as $stmt) {
                    if ($stmt instanceof ClassMethod && $stmt->name->toString() === $methodName) {
                        return $stmt;
                    }
                }
                return null;
            }

            /**
             * Check the closure-literal arguments of a NON-turbofish call to a non-generic
             * INSTANCE method. Reached only when {@see resolveMethodTemplate} found no generic
             * template, so a generic call is never double-checked here. The receiver's class
             * type arguments ground a `Closure(...)` parameter that references a class type
             * parameter; a first-class callable, an unresolvable method, or an unresolvable
             * receiver all stay gradual.
             */
            private function checkPlainInstanceClosureArgs(MethodCall|NullsafeMethodCall $node, string $classFqn, string $methodName): void
            {
                if ($this->closureValidator === null || $node->isFirstClassCallable()) {
                    return;
                }
                $found = $this->findMethodDeclaration($classFqn, $methodName);
                if ($found === null) {
                    return;
                }
                [$method, $declaringFqn] = $found;
                $subst = $this->classSubstitutionFor($classFqn, $this->resolveReceiverTypeArgs($node->var), $declaringFqn);
                $this->closureValidator->checkCallArguments(
                    array_values($method->params),
                    $node->args,
                    $subst,
                    $this->nsContext,
                    $this->currentFile,
                    $this->diagnostics,
                );
            }

            /**
             * Check the closure-literal arguments of a NON-turbofish call to a non-generic
             * STATIC method. A class type parameter is unbound in a static context, so the
             * substitution is empty — a `Closure(...)` target on a non-generic class is fully
             * concrete (checked), one referencing a class parameter stays abstract (gradual).
             */
            private function checkPlainStaticClosureArgs(StaticCall $node, string $classFqn, string $methodName): void
            {
                if ($this->closureValidator === null || $node->isFirstClassCallable()) {
                    return;
                }
                $found = $this->findMethodDeclaration($classFqn, $methodName);
                if ($found === null) {
                    return;
                }
                [$method] = $found;
                $this->closureValidator->checkCallArguments(
                    array_values($method->params),
                    $node->args,
                    Substitution::empty(),
                    $this->nsContext,
                    $this->currentFile,
                    $this->diagnostics,
                );
            }

            /**
             * Check the closure-literal arguments of a bare call to a NON-generic FREE
             * FUNCTION. The callee is resolved against the caller's function-name scope
             * (`use function`, current namespace, then the global fallback for an
             * unqualified name), so a non-generic higher-order function's `Closure(...)`
             * parameters are checked. A free function has no type parameters, so the
             * substitution is empty. An unresolved name stays gradual.
             */
            private function checkPlainFreeFunctionClosureArgs(FuncCall $node): void
            {
                if ($this->closureValidator === null || !$node->name instanceof Name) {
                    return;
                }
                $fn = $this->index->resolveFunction($node->name, $this->nsContext);
                if ($fn === null) {
                    return;
                }
                $this->closureValidator->checkCallArguments(
                    array_values($fn->params),
                    $node->args,
                    Substitution::empty(),
                    $this->nsContext,
                    $this->currentFile,
                    $this->diagnostics,
                );
            }

            /**
             * Handle a turbofish call whose generic method couldn't be resolved on
             * the receiver or any ancestor. A *turbofish* call (carries
             * ATTR_METHOD_GENERIC_ARGS) to a non-existent generic method is a real
             * user error -- reported here instead of being silently left in place
             * to fatal at runtime with "Call to undefined method". An ordinary
             * (non-turbofish) call has no generic args and is none of our business,
             * so it passes through untouched.
             *
             * Collect-or-throw, matching the seam: with a collector (`check`) append
             * a diagnostic and continue; without one (`compile`) throw.
             */
            private function reportUnresolvedTurbofishOrSkip(string $receiverFqn, string $methodName, Node $node): null
            {
                $args = $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
                if (!is_array($args)) {
                    // Plain (non-turbofish) call -- not a generic-resolution failure.
                    return null;
                }
                $message = self::unresolvedGenericCallMessage($receiverFqn, $methodName);
                if ($this->diagnostics !== null) {
                    $this->diagnostics->add(new Diagnostic(
                        Severity::Error,
                        GenericMethodCompiler::CODE_UNRESOLVED_GENERIC_CALL,
                        $message,
                        new SourceLocation($this->currentFile, $node->getStartLine()),
                    ));
                    return null;
                }
                throw new RuntimeException($message);
            }

            private static function unresolvedGenericCallMessage(string $receiverFqn, string $methodName): string
            {
                // Phrased as "could not be resolved ... on <receiver>" rather than
                // asserting the method is absent everywhere: the instance path walks
                // ancestors, but the static path doesn't yet, so an absolute "not on
                // any ancestor" claim would be wrong for an inherited static method.
                return sprintf(
                    'Generic method `%s::%s::<...>()` could not be resolved to a declared generic '
                    . 'method on `%s`. Check the method name or the receiver\'s type.',
                    $receiverFqn,
                    $methodName,
                    $receiverFqn,
                );
            }

            /**
             * A turbofish instance call (`$x->m::<...>()`) whose receiver type couldn't be determined.
             * The call can't be specialized — the generic method only exists as mangled
             * specializations — so leaving it would emit a call to a non-existent method that fatals
             * at runtime. Ground-or-fail: collect-or-throw at compile time. An ordinary (non-turbofish)
             * call has no generic args and passes through untouched (PHP resolves it normally).
             */
            private function reportUndeterminedReceiverOrSkip(string $methodName, Node $node): null
            {
                if (!is_array($node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS))) {
                    return null;
                }
                $message = sprintf(
                    'Cannot determine the receiver\'s type for the generic call `%s::<...>()`. A '
                    . 'turbofish call is specialized at compile time, so the receiver must have a '
                    . 'statically-known type. Give it a declared type — a typed parameter or property, '
                    . 'or a local assigned from `new ...::<...>()` or a typed return.',
                    $methodName,
                );
                if ($this->diagnostics !== null) {
                    $this->diagnostics->add(new Diagnostic(
                        Severity::Error,
                        GenericMethodCompiler::CODE_UNDETERMINED_RECEIVER,
                        $message,
                        new SourceLocation($this->currentFile, $node->getStartLine()),
                    ));
                    return null;
                }
                throw new RuntimeException($message);
            }

            /**
             * A `$this`-rooted self-call (`$this->m::<U>()`) forwards an abstract type parameter to a
             * generic method. It can't be specialized at the template — the arg is concrete only per
             * instantiation — and would otherwise emit a bare call to a stripped method that fatals at
             * runtime. Collect-or-throw at compile time. (A future erasure lowering will make the
             * common, direct-input shape of this compile and run.)
             */
            private function reportUnspecializableSelfCall(string $methodName, SourceLocation $location): null
            {
                $message = sprintf(
                    'Cannot specialize the self-call `$this->%s::<...>()`: it forwards a type parameter '
                    . 'to a generic method, which has no concrete value in the class template. Move the '
                    . 'call to a context where the receiver has a concrete element type (e.g. a function '
                    . 'taking a typed `Box<Fruit>`), or call the method on a directly-constructed value.',
                    $methodName,
                );
                if ($this->diagnostics !== null) {
                    $this->diagnostics->add(new Diagnostic(
                        Severity::Error,
                        GenericMethodCompiler::CODE_UNSPECIALIZABLE_SELF_CALL,
                        $message,
                        $location,
                    ));
                    return null;
                }
                throw new RuntimeException($message);
            }

            /**
             * A turbofish-less call to a generic free function or closure (`$label` is its FQN or
             * `$var`). Unlike a method, a named generic function / closure has no bare or empty-turbofish
             * form, so any bare call is missing its type arguments regardless of defaults. Collect-or-throw.
             */
            private function reportMissingTurbofishArguments(string $label, SourceLocation $location): void
            {
                $message = sprintf(
                    'Generic call `%s(...)` is missing its type arguments: a generic function or closure '
                    . 'takes no inference, so it must be called with an explicit turbofish `%s::<...>(...)`.',
                    $label,
                    $label,
                );
                if ($this->diagnostics !== null) {
                    $this->diagnostics->add(new Diagnostic(
                        Severity::Error,
                        Registry::CODE_MISSING_TYPE_ARGUMENT,
                        $message,
                        $location,
                    ));
                    return;
                }
                throw new RuntimeException($message);
            }

            /**
             * Whether the called method is an erasable `<U : E>` method on its declaring class — i.e.
             * the Specializer will lower it (and rewrite a forwarded self-call to it) per instantiation.
             *
             * @param list<TypeParam> $params
             */
            private function isErasableTarget(ClassMethod $template, array $params, string $declaringFqn): bool
            {
                $class = $this->index->classLike($declaringFqn);
                $classParams = $class?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                if (!is_array($classParams)) {
                    return false;
                }
                /** @var list<TypeParam> $classParams */
                $names = array_map(static fn (TypeParam $p): string => $p->name, $classParams);
                return EnclosingBoundErasure::isErasable($template, $params, $names);
            }

            /**
             * Resolve the static type (FQN) of a method-call receiver expression.
             * Returns null when the receiver type can't be determined -- the caller
             * uses null to mean "no specialization, leave the call site alone".
             *
             * Stage A handles two shapes:
             *   - `$this`     -> enclosing class FQN (tracked on ClassLike enter).
             *   - `$paramName` where paramName has a typed declaration in the
             *                  current function/method's signature.
             */
            private function resolveReceiverFqn(Node $receiver): ?string
            {
                if ($receiver instanceof Variable && is_string($receiver->name)) {
                    if ($receiver->name === 'this') {
                        return $this->currentClassFqn;
                    }
                    return $this->currentScopeParamTypes[$receiver->name]
                        ?? $this->currentScopeLocalTypes[$receiver->name]
                        ?? null;
                }
                if ($receiver instanceof PropertyFetch
                    && $receiver->var instanceof Variable
                    && $receiver->var->name === 'this'
                    && $receiver->name instanceof Identifier
                    && $this->currentClassFqn !== null
                ) {
                    // `$this->prop->method::<T>(...)` -- look up `prop`'s declared
                    // type on the current class.
                    $owner = $this->index->classLike($this->currentClassFqn);
                    if ($owner !== null) {
                        $propName = $receiver->name->toString();
                        foreach ($owner->stmts as $stmt) {
                            if (!$stmt instanceof Property) {
                                continue;
                            }
                            foreach ($stmt->props as $prop) {
                                if ($prop->name->toString() !== $propName) {
                                    continue;
                                }
                                $type = $stmt->type;
                                if ($type instanceof NullableType) {
                                    $type = $type->type;
                                }
                                if ($type instanceof Name) {
                                    return $this->resolveClassName($type);
                                }
                            }
                        }
                    }
                }
                // A chained call (`$this->getBox()->m::<…>()`): the receiver is itself a call, so its
                // type is that call's declared return type.
                if ($receiver instanceof MethodCall
                    || $receiver instanceof NullsafeMethodCall
                    || $receiver instanceof StaticCall
                ) {
                    $return = $this->resolveCallReturn($receiver);
                    return $return === null ? null : $return[0];
                }
                return null;
            }

            /**
             * Whether the receiver expression bottoms out at `$this` — directly (`$this->m`), through
             * a property (`$this->prop->m`), or through a chain (`$this->getBox()->m`). Used only to
             * tailor the `xphp.bound_unprovable` remedy: a `$this`-rooted self-call references the
             * enclosing class's own (abstract) type parameter, so the "bind to a typed local" advice
             * doesn't apply and a self-call-specific message is shown instead. It does NOT change
             * whether the bound fails — an unprovable bound always fails.
             */
            private function receiverRootedAtThis(Node $receiver): bool
            {
                if ($receiver instanceof Variable) {
                    return $receiver->name === 'this';
                }
                if ($receiver instanceof MethodCall
                    || $receiver instanceof NullsafeMethodCall
                    || $receiver instanceof PropertyFetch
                ) {
                    return $this->receiverRootedAtThis($receiver->var);
                }
                return false;
            }

            /**
             * The receiver's concrete generic type arguments, parallel to {@see resolveReceiverFqn}:
             *   - `$this`        -> the enclosing class's own type params, as identity TypeRefs (a
             *                       method-generic bound on the uninstantiated template can't be
             *                       grounded to a concrete, so this stays a type-param and drops).
             *   - `$var`         -> the side-tabled args for that parameter / local.
             *   - `$this->prop`  -> the property type's generic args.
             * Empty when unknown -- the grounding step then falls back to lenient.
             *
             * @return list<TypeRef>
             */
            private function resolveReceiverTypeArgs(Node $receiver): array
            {
                if ($receiver instanceof Variable && is_string($receiver->name)) {
                    if ($receiver->name === 'this') {
                        $owner = $this->currentClassFqn !== null
                            ? ($this->index->classLike($this->currentClassFqn))
                            : null;
                        $params = $owner?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                        if (!is_array($params)) {
                            return [];
                        }
                        /** @var list<TypeParam> $params */
                        return array_map(
                            static fn (TypeParam $p): TypeRef => new TypeRef($p->name, isTypeParam: true),
                            $params,
                        );
                    }
                    return $this->currentScopeParamTypeArgs[$receiver->name]
                        ?? $this->currentScopeLocalTypeArgs[$receiver->name]
                        ?? [];
                }
                if ($receiver instanceof PropertyFetch
                    && $receiver->var instanceof Variable
                    && $receiver->var->name === 'this'
                    && $receiver->name instanceof Identifier
                    && $this->currentClassFqn !== null
                ) {
                    $owner = $this->index->classLike($this->currentClassFqn);
                    if ($owner !== null) {
                        $propName = $receiver->name->toString();
                        foreach ($owner->stmts as $stmt) {
                            if (!$stmt instanceof Property) {
                                continue;
                            }
                            foreach ($stmt->props as $prop) {
                                if ($prop->name->toString() !== $propName) {
                                    continue;
                                }
                                $type = $stmt->type;
                                if ($type instanceof NullableType) {
                                    $type = $type->type;
                                }
                                if ($type instanceof Name) {
                                    $propArgs = $type->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
                                    /** @var list<TypeRef> $result */
                                    $result = is_array($propArgs) ? $propArgs : [];
                                    return $result;
                                }
                            }
                        }
                    }
                }
                // A chained call (`$this->getBox()->m::<…>()`): the receiver's args are the return
                // type's grounded args.
                if ($receiver instanceof MethodCall
                    || $receiver instanceof NullsafeMethodCall
                    || $receiver instanceof StaticCall
                ) {
                    $return = $this->resolveCallReturn($receiver);
                    return $return === null ? [] : $return[1];
                }
                return [];
            }

            /**
             * The class FQN and concrete generic type-args of a method/static call's DECLARED return
             * type, or `null` when the return type isn't a determinable class. This is what lets a
             * receiver whose type comes from a call — `$x = $repo->find(); $x->m::<…>()`, a chained
             * `$this->getBox()->m::<…>()`, or a `self`/`static`/`parent`-returning factory — be
             * grounded instead of treated as opaque.
             *
             * The return type's own generic args may reference the CALLED method's class parameters
             * (`Repo<E> { getBox(): Box<E> }`); those are grounded through the call receiver's args
             * (so `Box<E>` on a `Repo<Fruit>` receiver becomes `Box<Fruit>`). When the receiver's args
             * are themselves abstract (a `$this` self-call inside the template, or an unknown
             * receiver) the arg stays a type parameter and the downstream grounding drops it — no
             * determinate type is ever invented.
             *
             * @return array{0: string, 1: list<TypeRef>}|null  [returnFqn, groundedReturnArgs]
             */
            private function resolveCallReturn(Node $call): ?array
            {
                $key = spl_object_id($call);
                if (array_key_exists($key, $this->callReturnCache)) {
                    return $this->callReturnCache[$key];
                }
                return $this->callReturnCache[$key] = $this->computeCallReturn($call);
            }

            /**
             * The uncached body of {@see resolveCallReturn}; always go through the memoizing wrapper.
             *
             * @return array{0: string, 1: list<TypeRef>}|null
             */
            private function computeCallReturn(Node $call): ?array
            {
                if ($call instanceof StaticCall) {
                    if (!$call->class instanceof Name || !$call->name instanceof Identifier) {
                        return null;
                    }
                    $receiverFqn = $this->resolveClassName($call->class);
                    $classArgs = $call->class->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
                    /** @var list<TypeRef> $receiverArgs */
                    $receiverArgs = is_array($classArgs) ? $classArgs : [];
                } elseif ($call instanceof MethodCall || $call instanceof NullsafeMethodCall) {
                    if (!$call->name instanceof Identifier) {
                        return null;
                    }
                    $receiverFqn = $this->resolveReceiverFqn($call->var);
                    if ($receiverFqn === null) {
                        return null;
                    }
                    $receiverArgs = $this->resolveReceiverTypeArgs($call->var);
                } else {
                    return null;
                }

                // A non-generic getter (`getBox(): Box<Fruit>`) is the common return source and is NOT
                // in $methodTemplates (which holds only generic methods), so resolve against the full
                // class AST, walking ancestors for an inherited declaration.
                $resolved = $this->findMethodDeclaration($receiverFqn, $call->name->toString());
                if ($resolved === null) {
                    return null;
                }
                [$method, $declaringFqn] = $resolved;
                $returnType = $method->returnType;
                if ($returnType instanceof NullableType) {
                    $returnType = $returnType->type;
                }
                if (!$returnType instanceof Name) {
                    return null;
                }

                // `self` / `static` / `parent` return the receiver's own generic instance: its class
                // and args carry through unchanged. (The parser strips the `<…>` off a pseudo-type
                // and records no marker, so these names carry no ATTR_TEMPLATE_FQN.)
                if (in_array(strtolower($returnType->toString()), ['self', 'static', 'parent'], true)) {
                    return [$receiverFqn, $receiverArgs];
                }

                // A parameterised return type (`Box<…>`) carries its head FQN on ATTR_TEMPLATE_FQN and
                // its args on ATTR_GENERIC_ARGS; a plain class return type carries ATTR_RESOLVED_FQN.
                $returnFqn = $returnType->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (!is_string($returnFqn)) {
                    $plainFqn = $returnType->getAttribute(XphpSourceParser::ATTR_RESOLVED_FQN);
                    return is_string($plainFqn) ? [$plainFqn, []] : null;
                }
                $returnArgs = $returnType->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
                /** @var list<TypeRef> $returnArgs */
                $returnArgs = is_array($returnArgs) ? $returnArgs : [];

                // Ground the return args through the receiver: `getBox(): Box<E>` on a `Repo<Fruit>`
                // receiver yields `Box<Fruit>`. A concrete return parameterisation has no class-param
                // leaves, so the substitution is a no-op for it.
                $classSubst = $this->classSubstitutionFor($receiverFqn, $receiverArgs, $declaringFqn);
                if (!$classSubst->isEmpty()) {
                    $returnArgs = array_map(
                        static fn (TypeRef $arg): TypeRef => Specializer::substituteTypeRef($arg, $classSubst),
                        $returnArgs,
                    );
                }
                return [$returnFqn, $returnArgs];
            }

            /**
             * Ground each method type-param's bound against the receiver's concrete arguments and the
             * call's own turbofish arguments.
             *
             * Builds a substitution from (a) the declaring class's parameters to the receiver's
             * arguments (threaded up the inheritance chain) and (b) the method's own parameters to
             * this call's turbofish arguments — so a bound that references a SIBLING method parameter
             * (`<U, V : U>`) grounds to that argument too. Method parameters shadow class parameters
             * of the same name (the inner scope wins). It then rewrites every bound leaf with the
             * combined map.
             *
             * When a leaf is still a bare type parameter afterwards the bound is UNPROVABLE — the
             * receiver's type argument for it isn't determinable here. Maximum Runtime Safety forbids
             * silently accepting it, so this is a compile error (`xphp.bound_unprovable`); it is never
             * dropped. A bound that doesn't reference an enclosing/sibling param (a real class, or an
             * F-bounded `Comparable<T>` leaf) is unaffected and checked exactly as before.
             *
             * `$receiverIsThis` only tailors the diagnostic's remedy: a `$this`-rooted self-call can't
             * "bind to a typed local" (the receiver is `$this`), and its enclosing parameter is
             * abstract until the class is instantiated — a per-instantiation re-check (the future
             * relaxation) would turn this hard error into a real check. It does NOT suppress the
             * error: a `$this->m::<Concrete>()` self-call against an enclosing-param bound is checkable
             * per instantiation and currently checked by nobody, so it must fail rather than slip
             * through. (A self-call with no concrete turbofish never reaches grounding — the
             * all-concrete guard at the call site bails first — so it is unaffected.)
             *
             * @param list<TypeParam> $params
             * @param list<TypeRef> $methodArgs   the call's turbofish args, positionally per param
             * @param list<TypeRef> $receiverArgs
             * @return list<TypeParam>
             */
            private function groundBounds(
                array $params,
                array $methodArgs,
                string $receiverFqn,
                array $receiverArgs,
                string $declaringFqn,
                bool $receiverIsThis,
                string $context,
                SourceLocation $location,
            ): array {
                $classSubst = $this->classSubstitutionFor($receiverFqn, $receiverArgs, $declaringFqn);
                // Method-own params shadow class params of the same name, so they are layered last.
                $overlay = [];
                foreach ($params as $i => $param) {
                    if (isset($methodArgs[$i])) {
                        $overlay[$param->name] = $methodArgs[$i];
                    }
                }
                $subst = $classSubst->withOverrides(Substitution::of($overlay));

                $checked = [];
                foreach ($params as $param) {
                    if ($param->bound === null) {
                        $checked[] = $param;
                        continue;
                    }
                    $grounded = Registry::substituteBound($param->bound, $subst);
                    if (self::boundHasUngroundedLeaf($grounded)) {
                        $this->failUnprovableBound($param->name, $param->bound, $context, $receiverIsThis, $location);
                        // In check mode failUnprovableBound collects and returns; drop the now-checked
                        // bound so the pass can continue and surface any further diagnostics.
                        $checked[] = new TypeParam($param->name, null, $param->default, $param->variance);
                        continue;
                    }
                    $checked[] = new TypeParam($param->name, $grounded, $param->default, $param->variance);
                }
                return $checked;
            }

            /**
             * A method-generic bound references an enclosing/sibling type parameter whose concrete
             * value isn't determinable at this call site. Collect-or-throw (matching the seam): with
             * a collector (`check`) append the diagnostic and continue; without one (`compile`) throw.
             */
            private function failUnprovableBound(string $paramName, BoundExpr $bound, string $context, bool $receiverIsThis, SourceLocation $location): void
            {
                $message = self::unprovableBoundMessage($paramName, $bound, $context, $receiverIsThis);
                if ($this->diagnostics !== null) {
                    $this->diagnostics->add(new Diagnostic(
                        Severity::Error,
                        GenericMethodCompiler::CODE_BOUND_UNPROVABLE,
                        $message,
                        $location,
                    ));
                    return;
                }
                throw new RuntimeException($message);
            }

            private static function unprovableBoundMessage(string $paramName, BoundExpr $bound, string $context, bool $receiverIsThis): string
            {
                $boundText = Registry::formatBound($bound);
                if ($receiverIsThis) {
                    return sprintf(
                        'Cannot verify generic bound `%s : %s` for %s in a `$this`-rooted self-call: the bound '
                        . 'references the enclosing class\'s own type parameter, which is abstract in the class '
                        . 'template, so it can only be checked once the class is instantiated. Move this call to '
                        . 'a context where the receiver has a concrete element type (e.g. a function taking '
                        . '`Box<Fruit> $b` then `$b->%s::<...>(...)`), or don\'t turbofish an enclosing-parameter-'
                        . 'bounded method on `$this`. (A future per-instantiation bound check will relax this.)',
                        $paramName,
                        $boundText,
                        $context,
                        // The method name is the tail of the "Class::method" context.
                        substr($context, (int) strrpos($context, ':') + 1),
                    );
                }
                return sprintf(
                    'Cannot verify generic bound `%s : %s` for %s: the receiver\'s type argument is not '
                    . 'determinable at this call site, so the bound cannot be proven. Bind the receiver to a '
                    . 'typed local (e.g. `Box<Fruit> $x = ...;`) or pass it as a typed parameter so its type '
                    . 'arguments are known here.',
                    $paramName,
                    $boundText,
                    $context,
                );
            }

            /**
             * The declaring-class-parameter => receiver-argument substitution, or the empty
             * substitution when the
             * receiver's arguments can't be threaded to the declaring class (no hierarchy, an
             * unreachable/ambiguous chain, an arity mismatch, or a missing declaring class).
             *
             * @param list<TypeRef> $receiverArgs
             */
            private function classSubstitutionFor(string $receiverFqn, array $receiverArgs, string $declaringFqn): Substitution
            {
                if ($this->hierarchy === null) {
                    return Substitution::empty();
                }
                $declArgs = $this->hierarchy->resolveInheritedArgs($receiverFqn, $receiverArgs, $declaringFqn);
                if ($declArgs === null) {
                    return Substitution::empty();
                }
                $owner = $this->index->classLike($declaringFqn);
                $params = $owner?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                if (!is_array($params) || count($params) !== count($declArgs)) {
                    return Substitution::empty();
                }
                /** @var list<TypeParam> $params */
                $subst = [];
                foreach ($params as $i => $param) {
                    $subst[$param->name] = $declArgs[$i];
                }
                return Substitution::of($subst);
            }

            /**
             * Whether any leaf of the bound is still a bare type parameter (`isTypeParam`) — i.e. an
             * enclosing class/method parameter that substitution couldn't ground. A leaf naming a
             * real class with type-param ARGS (`Comparable<T>`) is not "ungrounded": the hierarchy
             * checks it erased on the leaf name, exactly as today.
             */
            private static function boundHasUngroundedLeaf(BoundExpr $bound): bool
            {
                if ($bound instanceof BoundLeaf) {
                    return $bound->type->isTypeParam;
                }
                if ($bound instanceof BoundIntersection || $bound instanceof BoundUnion) {
                    foreach ($bound->operands as $operand) {
                        if (self::boundHasUngroundedLeaf($operand)) {
                            return true;
                        }
                    }
                }
                return false;
            }

            private function rewriteFuncCall(FuncCall $node): ?Node
            {
                $args = $node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS);
                if (!is_array($args)) {
                    // Bare (turbofish-less) call. A first-class-callable (`f(...)` / `$f(...)`) creates a
                    // Closure rather than invoking, so leave it alone. Otherwise a generic free function
                    // or tracked generic closure has no bare or empty-turbofish form (a named generic
                    // takes no inference and the dispatcher needs concrete args), so ANY bare call to one
                    // is a missing-type-arguments error — not a silent skip that emits a call to the
                    // stripped `f_T_<…>`. Both lookups hold ONLY generics, so a non-generic bare call
                    // resolves to nothing and is left untouched — no false positives.
                    if (!$node->isFirstClassCallable()) {
                        $bare = $this->resolveBareGenericCall($node);
                        if ($bare !== null) {
                            $this->reportMissingTurbofishArguments(
                                $bare[1],
                                new SourceLocation($this->currentFile, $node->getStartLine()),
                            );
                        } else {
                            // Not a generic template — a bare call to a NON-generic free
                            // function. Check its closure-literal arguments against the
                            // callee's Closure(...) parameters.
                            $this->checkPlainFreeFunctionClosureArgs($node);
                        }
                    }
                    return null;
                }
                /** @var list<TypeRef> $args — set as a list by XphpSourceParser::resolveAndAttach. */
                $isVarTurbofish = $node->name instanceof Variable && is_string($node->name->name);
                // Empty turbofish (`$f::<>(...)`) is the all-defaults shape for
                // variable-turbofish call sites (P5.7); the dispatcher path
                // pads via `Registry::padArgsWithDefaults`. For named-call
                // turbofish, empty-args is still invalid -- the original
                // call-site rewriter expects concrete args.
                if (!$isVarTurbofish && ($args === [] || !self::allConcrete($args))) {
                    return null;
                }
                if ($isVarTurbofish && $args !== [] && !self::allConcrete($args)) {
                    // The author DID write a turbofish for this template, but its type
                    // arguments are not concrete here (`$inner::<S>($v)` where `S` is grounded
                    // only by a still-abstract enclosing template). The variable-turbofish
                    // dispatcher can ground only concrete arguments, so this site cannot be
                    // specialized: the closure's type-parameter hints would reach the emitted
                    // code as references to non-existent classes and fatal on invocation. Reject
                    // it loudly in BOTH modes (the compile-only emitted-marker backstop is the
                    // last-resort net for the shapes that never reach this branch — e.g. a
                    // *concrete* inner turbofish inside a generic function). Still mark the
                    // template attempted so the orphan check does not additionally misdiagnose
                    // it as never-called (a double report for the same template).
                    $template = $this->currentScopeClosureTemplates[$node->name->name] ?? null;
                    if ($template !== null) {
                        $this->attemptedClosureTemplates[spl_object_id($template)] = true;
                    }
                    $message = sprintf(
                        'Generic closure call `$%s::<%s>(...)` cannot be specialized: its type '
                        . 'argument(s) are grounded only by an enclosing generic scope and are not '
                        . 'concrete here, so the closure\'s type-parameter hints would reach the '
                        . 'emitted code as references to non-existent classes. Call it with an '
                        . 'explicit concrete turbofish, or restructure so the closure is not '
                        . 'grounded by an enclosing type parameter.',
                        $node->name->name,
                        self::formatArgList($args),
                    );
                    if ($this->diagnostics !== null) {
                        $this->diagnostics->add(new Diagnostic(
                            Severity::Error,
                            GenericMethodCompiler::CODE_UNSPECIALIZED_GENERIC_CLOSURE,
                            $message,
                            new SourceLocation($this->currentFile, $node->getStartLine()),
                        ));
                        return null;
                    }
                    throw new RuntimeException($message);
                }
                // Variable turbofish `$var::<T>(...)` / `$var::<>(...)`:
                // dispatched to a separate path that looks up the variable's
                // tracked closure template and routes through the P5.4
                // dispatcher. Defaults pad missing trailing args (P5.7).
                if ($isVarTurbofish) {
                    return $this->rewriteVariableTurbofishCall($node, $args);
                }
                if (!$node->name instanceof Name) {
                    return null;
                }

                $fqn = $node->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                if (!is_string($fqn)) {
                    return null;
                }
                $template = $this->index->functionTemplate($fqn);
                if ($template === null) {
                    return null;
                }
                $params = $template->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                if (!is_array($params) || count($params) !== count($args)) {
                    return null;
                }
                /** @var list<TypeParam> $params — set as a list by XphpSourceParser::resolveAndAttach. */

                if ($this->hierarchy !== null) {
                    Registry::checkBounds(
                        $params,
                        $args,
                        $this->hierarchy,
                        $fqn . '<' . self::formatArgList($args) . '>',
                        $this->diagnostics,
                        new SourceLocation($this->currentFile, $node->getStartLine()),
                    );

                    // Check each closure-literal call argument against its paired parameter's
                    // Closure(...) target, grounded through this call's type arguments. A free
                    // function has no enclosing class, so the substitution carries only its own
                    // (already-concrete) method type arguments.
                    if ($this->closureValidator !== null) {
                        $overlay = [];
                        foreach ($params as $i => $param) {
                            $overlay[$param->name] = $args[$i];
                        }
                        $this->closureValidator->checkCallArguments(
                            array_values($template->params),
                            $node->args,
                            Substitution::of($overlay),
                            $this->nsContext,
                            $this->currentFile,
                            $this->diagnostics,
                        );
                    }
                }

                $funcName = $template->name->toString();
                $mangled = self::mangleName($funcName, $args, $this->hashLength);
                $pos = strrpos($fqn, '\\');
                $namespace = $pos === false ? '' : substr($fqn, 0, $pos);
                $mangledFqn = $namespace !== '' ? $namespace . '\\' . $mangled : $mangled;
                $generatedKey = 'fn::' . $mangledFqn;

                if (!isset($this->alreadyGenerated[$generatedKey])) {
                    // Grounding a specialized class: a BARE top-level function template
                    // has no container node, and the top-level bag routes into whatever
                    // file the walk was invoked for — which, for a detached spec, is no
                    // file at all (the append would be dropped silently). Keep the
                    // marker instead; the emit backstop rejects it loudly.
                    if ($this->grounding !== null && $this->index->functionNamespaceNode($fqn) === null) {
                        return null;
                    }
                    $overlay = [];
                    foreach ($params as $i => $param) {
                        $overlay[$param->name] = $args[$i];
                    }
                    $specialized = (new Specializer())->specializeFunction($template, Substitution::of($overlay), $mangled);
                    $namespaceNode = $this->index->functionNamespaceNode($fqn);
                    if ($namespaceNode !== null) {
                        // Buffer the append — modifying $namespaceNode->stmts mid-traversal
                        // doesn't reliably propagate through nikic's NodeTraverser. The
                        // outer process() loop flushes pendingAppends after the walk.
                        $this->pendingAppends[] = [$namespaceNode, $specialized, [
                            'classFqn'  => null,
                            'namespace' => $namespace,
                        ]];
                    } else {
                        // Bare top-level template (no enclosing `namespace { }` block):
                        // there's no container to append to, so route the specialized
                        // function through the topLevelAppends out-param; process() flushes
                        // it directly into the top-level AST array after this visitor returns.
                        $this->topLevelAppends[] = $specialized;
                    }
                    $this->alreadyGenerated[$generatedKey] = true;
                }

                $node->name = new FullyQualified($mangledFqn, $node->name->getAttributes());
                $node->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS, null);
                $node->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, null);

                return $node;
            }

            /**
             * Record a `$var::<T>(...)` call site against the in-flight closure
             * dispatch plan. Rejections for arrow / `use` / static closures
             * fire EAGERLY (same throws as pre-P5.4), before any bag mutation.
             *
             * Two-pass model (P5.4):
             *
             *   Pass 1 (this method): per call site, validate eagerly and
             *   record the arg-tuple + FuncCall node into a per-template bag
             *   keyed by `(varName, $template->getStartFilePos())`. NO
             *   immediate rewrite or emit.
             *
             *   Pass 2 (`finalizeClosureDispatchers`): after the traverser
             *   returns, materialize ONE dispatcher closure per bag entry
             *   via `ClosureDispatcher::dispatch(...)`, replace the original
             *   Assign's RHS in place, append specialized declarations, and
             *   rewrite every recorded FuncCall to inject the tag arg.
             *
             * @param list<TypeRef> $args
             */
            private function rewriteVariableTurbofishCall(FuncCall $node, array $args): ?Node
            {
                assert($node->name instanceof Variable && is_string($node->name->name));
                $varName = $node->name->name;
                $template = $this->currentScopeClosureTemplates[$varName] ?? null;
                if ($template === null) {
                    return null;
                }
                // Attempted — even if this call draws one of the eager rejects
                // below, the template is not an ORPHAN and must not draw a
                // second (unspecialized-closure) error for the same root cause.
                $this->attemptedClosureTemplates[spl_object_id($template)] = true;

                // Eager rejections -- preserved from pre-P5.4 behavior so the
                // throw fires at the first offending call site, before the
                // bag mutates. P5.5 lifted the arrow rejection by routing
                // implicit captures through the dispatcher's `use (...)`
                // clause; static closures and explicit `use (...)` closures
                // are still pending (P5.6).
                if (ClosureDispatcher::usesThis($template)) {
                    // P5.5 / P5.6 reject `$this`-capturing generic
                    // anonymous templates. The dispatcher closure can't
                    // carry `$this` through its `use` clause (PHP rejects
                    // `use ($this)`); the specialized top-level function
                    // also can't see the enclosing class's `$this`.
                    // A future commit can rewrite `$this->v` to a lifted
                    // param.
                    $flavor = $template instanceof ArrowFunction ? 'arrow' : 'closure';
                    $message = sprintf(
                        'Generic %s `$%s::<...>(...)` captures `$this`, '
                        . 'which is not yet supported. Rewrite as a method '
                        . 'on the enclosing class, or extract the value of '
                        . '$this->property into a local variable before '
                        . 'the %s.',
                        $flavor,
                        $varName,
                        $flavor,
                    );
                    if ($this->diagnostics !== null) {
                        $this->diagnostics->add(new Diagnostic(
                            Severity::Error,
                            GenericMethodCompiler::CODE_UNSUPPORTED_THIS_CAPTURE,
                            $message,
                            new SourceLocation($this->currentFile, $node->getStartLine()),
                        ));
                        return null;
                    }
                    throw new RuntimeException($message);
                }
                if ($template instanceof Closure && $template->static) {
                    $message = sprintf(
                        'Generic static closures cannot yet be specialized at '
                        . 'call sites. Rewrite the call site for `$%s::<...>(...)` '
                        . 'to use a named generic function at file scope.',
                        $varName,
                    );
                    if ($this->diagnostics !== null) {
                        $this->diagnostics->add(new Diagnostic(
                            Severity::Error,
                            GenericMethodCompiler::CODE_UNSUPPORTED_STATIC_CLOSURE,
                            $message,
                            new SourceLocation($this->currentFile, $node->getStartLine()),
                        ));
                        return null;
                    }
                    throw new RuntimeException($message);
                }

                $params = $template->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                if (!is_array($params)) {
                    return null;
                }
                /** @var list<TypeParam> $params — set as a list by XphpSourceParser::resolveAndAttach. */
                // P5.7: pad missing trailing args with defaults BEFORE
                // the arity check so `$f::<>()` works on an all-defaulted
                // generic closure / arrow. Padding throws when leading
                // required params are missing -- the throw surfaces with
                // a clear `Registry::padArgsWithDefaults` message.
                $location = new SourceLocation($this->currentFile, $node->getStartLine());
                $args = Registry::padArgsWithDefaults($params, $args, 'closure<' . $varName . '>', $this->diagnostics, $location);
                if (count($params) !== count($args)) {
                    return null;
                }
                if ($this->hierarchy !== null) {
                    Registry::checkBounds(
                        $params,
                        $args,
                        $this->hierarchy,
                        'closure<' . self::formatArgList($args) . '>',
                        $this->diagnostics,
                        $location,
                    );
                }
                $context = $this->currentScopeClosureContexts[$varName] ?? null;
                if ($context === null) {
                    return null;
                }

                $planKey = $varName . '@' . $template->getStartFilePos();
                if (!isset($this->closureDispatchPlan[$planKey])) {
                    // Compute the dispatcher's `use (...)` clause once per
                    // template -- captures don't change between call sites.
                    // Arrows get implicit-capture analysis; closures use
                    // their explicit `use` list (empty for the capture-free
                    // case P5.4 shipped).
                    $useClauses = $template instanceof ArrowFunction
                        ? ClosureDispatcher::implicitCapturesOf($template)
                        : $template->uses;
                    $this->closureDispatchPlan[$planKey] = [
                        'template'      => $template,
                        'varName'       => $varName,
                        'assignNode'    => $context['assign'],
                        'namespace'     => $context['namespace'],
                        'namespaceNode' => $context['namespaceNode'],
                        'typeParams'    => $params,
                        'callSites'     => [],
                        'argSets'       => [],
                        'seenTagSet'    => [],
                        'useClauses'    => $useClauses,
                    ];
                }
                $entry = &$this->closureDispatchPlan[$planKey];
                $tag = ClosureDispatcher::tagFor($args, $this->hashLength);
                if (!isset($entry['seenTagSet'][$tag])) {
                    $entry['seenTagSet'][$tag] = true;
                    $entry['argSets'][] = $args;
                }
                unset($entry);

                // First-class callable of a turbofish specialization (`$g = $f::<int>(...)`).
                // The call site's args are just the `...` placeholder, so prepending the tag arg
                // (the direct-call path below) would emit `$f('T_…', ...)` — illegal PHP that does
                // not parse. Instead return a forwarding closure that binds the tag and forwards
                // through the dispatcher `$varName` (materialized by finalize from the argSet just
                // recorded), preserving callable semantics (arity, variadics, named args, and the
                // captures the dispatcher already carries). It is NOT added to `callSites`, so
                // finalize leaves it alone.
                if ($node->isFirstClassCallable()) {
                    return $this->buildForwardingFccClosure($varName, $tag, $node);
                }

                $entry = &$this->closureDispatchPlan[$planKey];
                // Pair each call site with its PADDED tag so finalize doesn't
                // recompute from the original `ATTR_METHOD_GENERIC_ARGS` (which
                // may be empty for the `$f::<>()` default-padding shape).
                $entry['callSites'][] = ['node' => $node, 'tag' => $tag];
                unset($entry);
                // Do NOT mutate the call site yet -- pass 2 prepends the tag arg
                // once the dispatcher's specializations are known.
                return null;
            }

            /**
             * Build the forwarding closure that replaces a first-class-callable turbofish
             * (`$f::<int>(...)`): `fn(...$p) => $f('<tag>', ...$p)`. Calling it routes through the
             * dispatcher `$varName` (which finalize installs in that variable) to the specialization
             * named by `$tag`. The variadic param name is guaranteed distinct from `$varName` so it
             * never shadows the captured dispatcher inside the body.
             */
            private function buildForwardingFccClosure(string $varName, string $tag, FuncCall $node): ArrowFunction
            {
                $paramName = '__xphp_fcc_args';
                while ($paramName === $varName) {
                    $paramName .= '_';
                }
                $forward = new FuncCall(
                    new Variable($varName),
                    [
                        new Arg(new String_($tag)),
                        new Arg(new Variable($paramName), false, true),
                    ],
                );
                return new ArrowFunction(
                    [
                        'params' => [new Param(new Variable($paramName), null, null, false, true)],
                        'expr'   => $forward,
                    ],
                    $node->getAttributes(),
                );
            }

            private function resolveClassName(Name $name): string
            {
                $raw = $name->toString();
                // Pseudo-types short-circuit to the enclosing class FQN. Without this,
                // a parameter typed `self` would resolve to `App\…\self` (a phantom
                // class), and any `$param->m::<T>()` call on it would miss the
                // template lookup. Same gap as the scanner's pseudo-type filter --
                // they need to stay in sync. `currentClassFqn` is null only at top
                // level (no enclosing ClassLike), where pseudo-types aren't legal
                // anyway; fall through to the namespace path so the user sees PHP's
                // own "cannot use self outside class context" error.
                $lower = strtolower($raw);
                if ($this->currentClassFqn !== null
                    && ($lower === 'self' || $lower === 'static' || $lower === 'parent')
                ) {
                    return $this->currentClassFqn;
                }
                if ($name instanceof FullyQualified) {
                    return $name->toString();
                }
                if (str_starts_with($raw, '\\')) {
                    return ltrim($raw, '\\');
                }
                // `namespace\Svc` binds to the current namespace — never to a
                // `use` alias (toString() erased the prefix, so ask the node).
                // Alias capture here made the receiver's method-template lookup
                // land on the wrong class.
                if ($name->isRelative()) {
                    return $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $raw
                        : $raw;
                }
                $first = self::firstSegment($raw);
                if (isset($this->useMap[$first])) {
                    $rest = substr($raw, strlen($first));
                    return $this->useMap[$first] . $rest;
                }
                return $this->currentNamespace !== ''
                    ? $this->currentNamespace . '\\' . $raw
                    : $raw;
            }

            /**
             * For a turbofish-less call, return the called generic template's `[params, label]` — a
             * registered generic free function (by resolved FQN) or a tracked generic closure (by
             * variable) — or null when the callee isn't a generic template (non-generic calls, unknown
             * variables). The label is the diagnostic's template name.
             *
             * @return array{0: list<TypeParam>, 1: string}|null
             */
            private function resolveBareGenericCall(FuncCall $node): ?array
            {
                if ($node->name instanceof Name) {
                    $fqn = $this->resolveGenericFunctionFqn($node->name);
                    if ($fqn === null) {
                        return null;
                    }
                    $params = $this->index->functionTemplate($fqn)
                        ?->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                    /** @var list<TypeParam>|null $params */
                    return is_array($params) ? [$params, $fqn] : null;
                }
                if ($node->name instanceof Variable && is_string($node->name->name)) {
                    $template = $this->currentScopeClosureTemplates[$node->name->name] ?? null;
                    if ($template === null) {
                        return null;
                    }
                    // A bare call to a generic closure draws the loud
                    // missing-turbofish reject — that already covers the
                    // template, so it is not an orphan too.
                    $this->attemptedClosureTemplates[spl_object_id($template)] = true;
                    $params = $template->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                    /** @var list<TypeParam>|null $params */
                    return is_array($params) ? [$params, '$' . $node->name->name] : null;
                }
                return null;
            }

            /**
             * Resolve a (turbofish-less) function-call name to the FQN of a registered GENERIC function
             * template, or null if it isn't one. `functionTemplates` holds only generic functions, so a
             * non-generic call (e.g. `strlen`) resolves to null and is left untouched. Mirrors PHP's
             * function name resolution: fully-qualified and `use`-aliased names resolve directly; an
             * unqualified name tries the current namespace first, then falls back to the global scope.
             */
            private function resolveGenericFunctionFqn(Name $name): ?string
            {
                if ($name instanceof FullyQualified || str_starts_with($name->toString(), '\\')) {
                    $fqn = ltrim($name->toString(), '\\');
                    return $this->index->hasFunctionTemplate($fqn) ? $fqn : null;
                }
                $raw = $name->toString();
                $first = self::firstSegment($raw);
                if (isset($this->useMap[$first])) {
                    $fqn = $this->useMap[$first] . substr($raw, strlen($first));
                    return $this->index->hasFunctionTemplate($fqn) ? $fqn : null;
                }
                if ($this->currentNamespace !== '') {
                    $namespaced = $this->currentNamespace . '\\' . $raw;
                    if ($this->index->hasFunctionTemplate($namespaced)) {
                        return $namespaced;
                    }
                }
                // Global-scope fallback (PHP resolves an unqualified function to global when the
                // namespaced one doesn't exist).
                return $this->index->hasFunctionTemplate($raw) ? $raw : null;
            }

            /**
             * @param list<TypeRef> $args
             */
            private static function allConcrete(array $args): bool
            {
                foreach ($args as $a) {
                    if (!$a->isConcrete()) {
                        return false;
                    }
                }
                return true;
            }

            /**
             * @param list<TypeRef> $args
             */
            private static function mangleName(string $shortName, array $args, int $hashLength): string
            {
                return Registry::mangledMethodName($shortName, $args, $hashLength);
            }

            /**
             * Display-style "T1, T2, ..." for the bound-violation error context.
             *
             * @param list<TypeRef> $args
             */
            private static function formatArgList(array $args): string
            {
                return implode(', ', array_map(static fn (TypeRef $r): string => $r->toDisplayString(), $args));
            }

            private static function firstSegment(string $name): string
            {
                $pos = strpos($name, '\\');
                return $pos === false ? $name : substr($name, 0, $pos);
            }

            private static function lastSegment(string $name): string
            {
                $pos = strrpos($name, '\\');
                return $pos === false ? $name : substr($name, $pos + 1);
            }

            /** The namespace part of an FQN ('' for a global-namespace symbol). */
            private static function namespaceOf(string $fqn): string
            {
                $pos = strrpos($fqn, '\\');
                return $pos === false ? '' : substr($fqn, 0, $pos);
            }

            /**
             * A function/method/closure declaration still carrying its generic-template
             * marker — never present in a compile-mode spec (templates are stripped or
             * lowered before cloning), but present in check-mode clones; markers-only
             * walks skip them wholesale (see enterNode).
             */
            private static function isUnspecializedTemplateDeclaration(Node $node): bool
            {
                return ($node instanceof ClassMethod
                        || $node instanceof Function_
                        || $node instanceof Closure
                        || $node instanceof ArrowFunction)
                    && is_array($node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS));
            }

            /**
             * A `static::` / `parent::` class spelling — late-bound (or parent-side)
             * dispatch that `resolveClassName`'s currentClassFqn mapping would silently
             * mis-ground in a detached grounding walk.
             */
            private function isLateBoundPseudoName(Node $class): bool
            {
                if (!$class instanceof Name) {
                    return false;
                }
                $first = strtolower($class->getParts()[0]);
                return $first === 'static' || $first === 'parent';
            }

            /** Whether the FQN names a generic class template (declares type parameters). */
            private function isGenericTemplateClass(string $fqn): bool
            {
                $params = $this->index->classLike($fqn)?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                return is_array($params) && $params !== [];
            }

            /**
             * The enclosing template's type-parameter → concrete-argument map for the
             * spec being grounded, read off the RETAINED template node (the spec clone's
             * generic attributes were nulled by the Specializer).
             *
             * @return array<string, TypeRef>
             */
            private function groundingClassOverlay(): array
            {
                if ($this->grounding === null) {
                    return [];
                }
                $classParams = $this->index->classLike($this->grounding->templateFqn)
                    ?->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                if (!is_array($classParams)) {
                    return [];
                }
                /** @var list<TypeParam> $classParams — set as a list by XphpSourceParser::resolveAndAttach. */
                $overlay = [];
                foreach ($classParams as $i => $classParam) {
                    if (isset($this->grounding->classArgs[$i])) {
                        $overlay[$classParam->name] = $this->grounding->classArgs[$i];
                    }
                }
                return $overlay;
            }
        };

        if ($grounding !== null) {
            // Grounding a specialized class: markers-only walk, identity primed from
            // the context (the spec clone is nameless and namespace-less — enterNode
            // would never see a Namespace_/name to derive them from).
            $visitor->markersOnly = true;
            $visitor->grounding = $grounding;
            $visitor->primeDrainScope(
                $grounding->templateFqn,
                self::namespacePrefixOf($grounding->templateFqn),
            );
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        // Runs BEFORE the emit gate: check mode must collect the orphan
        // diagnostics too (this is a validation, not an emission side-effect).
        // Skipped in grounding mode: a markers-only walk records no attempts, so an
        // inner generic closure the template walk already handled would false-flag
        // as an orphan on every specialization.
        if ($grounding === null) {
            $this->rejectUnspecializedClosureTemplates($ast, $visitor->attemptedClosureTemplates, $currentFile);
        }

        // Pass 2 of the closure-dispatcher pipeline: materialize a dispatcher
        // closure per recorded template, replace the original Assign's RHS,
        // append specialized declarations, and rewrite each collected call
        // site to inject the tag arg. Compile-only: it mutates shared Assign
        // nodes and call sites, which check's discarded walk must not do.
        if ($emit) {
            $this->finalizeClosureDispatchers($visitor, $hashLength);
        }

        // Drain the buffered appends now that the traversal has finished, so we don't
        // fight nikic's NodeTraverser's child-array iteration semantics mid-walk. Runs
        // in BOTH modes: compile attaches, grounds, and backstops each appended body;
        // check re-traverses the (discarded) bodies validate-only so diagnostics that
        // only become provable after substitution are collected — keeping check and
        // compile verdicts aligned.
        $this->drainSpecializedAppends($visitor, $currentFile, $emit);
    }

    /**
     * Flush the buffered specialized appends as a grounding worklist.
     *
     * Each buffered stmt is a freshly specialized function/method whose body may itself
     * carry method-generic turbofish markers that substitution has made concrete
     * (`identity::<T>` inside `wrap<T>` becomes `identity::<int>` inside the buffered
     * `wrap_T_<hash>`). A flat flush would emit those bodies ungrounded — the leak-guard
     * backstop tripped on exactly that shape. Instead each stmt is attached (compile
     * mode), then re-traversed with the same rewrite visitor in markers-only mode so a
     * named-forward marker dispatches and may buffer further appends; the loop repeats
     * until both queues drain. Same-args cycles (`a<T>` forwarding to `b<T>` forwarding
     * back) terminate through the shared alreadyGenerated dedup — the second visit finds
     * the key set and only rewrites the call. A strictly-growing chain mints a fresh
     * mangled name every hop and is cut off at MAX_METHOD_SPECIALIZATION_HOPS with a
     * loud non-convergence error instead of an endless compile.
     *
     * Check mode traverses without attaching (the walked ASTs are discarded) and
     * degrades both the non-convergence error and the leak backstop to collected
     * diagnostics, so `xphp check` reports the shapes `compile` rejects.
     */
    private function drainSpecializedAppends(object $visitor, string $currentFile, bool $emit): void
    {
        // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
        $visitor->markersOnly = true;
        // @infection-ignore-all UnwrapFinally — the reset is defensive hygiene: this drain is
        // the visitor's last use (one visitor per rewriteCallSites call), so a leftover
        // markersOnly=true is dead state today; the finally guards future reuse, not behavior.
        try {
            $pendingIdx = 0;
            $topLevelIdx = 0;
            /** @var array<int, int> $hopDepth spl_object_id(stmt) => chain depth; absent = 1 (buffered by the user-code walk) */
            $hopDepth = [];
            while (true) {
                // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                if ($pendingIdx < count($visitor->pendingAppends)) {
                    // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                    [$container, $stmt, $context] = $visitor->pendingAppends[$pendingIdx++];
                    if ($emit) {
                        $container->stmts[] = $stmt;
                    }
                    // Grounding mode: a member appended onto anything but the spec
                    // itself (a non-generic user class, a function namespace) is
                    // invisible to the fixed-point loop's spec collection — record it
                    // so the caller can collect its nested instantiation needs.
                    // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                    if ($visitor->grounding !== null && $container !== $visitor->grounding->spec) {
                        $visitor->grounding->externalAppends[] = $stmt;
                    }
                // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                } elseif ($topLevelIdx < count($visitor->topLevelAppends)) {
                    // Top-level (null-namespace) functions have no container node here;
                    // process() flushes them into the top-level AST array after this
                    // method returns. They are still grounded + leak-checked like any
                    // other append.
                    // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                    $stmt = $visitor->topLevelAppends[$topLevelIdx++];
                    $context = ['classFqn' => null, 'namespace' => ''];
                } else {
                    break;
                }

                // @infection-ignore-all IncrementInteger DecrementInteger — shifting the
                // initial depth by one only offsets where the cap lands (16±1 hops); the
                // behavior — a growing chain halts loudly with the unconverged code — is
                // pinned by the growth fixtures, and the exact allowance is not contract.
                $depth = $hopDepth[spl_object_id($stmt)] ?? 1;
                if ($depth > self::MAX_METHOD_SPECIALIZATION_HOPS) {
                    $message = sprintf(
                        'Generic method/function specialization did not converge: grounding "%s" '
                        . '(in %s) is %d specialization hops deep — each hop mints a new type argument '
                        . '(e.g. a generic forwarding to itself with a nested `Box<T>`), so the chain '
                        . 'would never terminate. Break the growth by forwarding a concrete turbofish. [%s]',
                        $stmt->name->toString(),
                        $currentFile,
                        $depth,
                        self::CODE_UNCONVERGED_METHOD_SPECIALIZATION,
                    );
                    if (!$emit && $this->diagnostics !== null) {
                        $this->diagnostics->add(new Diagnostic(
                            Severity::Error,
                            self::CODE_UNCONVERGED_METHOD_SPECIALIZATION,
                            $message,
                            new SourceLocation($currentFile, $stmt->getStartLine()),
                        ));
                        continue;
                    }
                    throw new RuntimeException($message);
                }

                // @phpstan-ignore-next-line method.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                $visitor->primeDrainScope($context['classFqn'], $context['namespace']);
                // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                $beforePending = count($visitor->pendingAppends);
                // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                $beforeTopLevel = count($visitor->topLevelAppends);

                $traverser = new NodeTraverser();
                assert($visitor instanceof NodeVisitorAbstract);
                $traverser->addVisitor($visitor);
                $traverser->traverse([$stmt]);

                // @infection-ignore-all IncrementInteger Plus — a coarser per-hop increment
                // only halves/offsets the cap allowance; growth still halts loudly with the
                // unconverged code (pinned by the growth fixtures) at the same reported depth.
                // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                for ($i = $beforePending; $i < count($visitor->pendingAppends); $i++) {
                    // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                    $hopDepth[spl_object_id($visitor->pendingAppends[$i][1])] = $depth + 1;
                }
                // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                for ($i = $beforeTopLevel; $i < count($visitor->topLevelAppends); $i++) {
                    // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                    $hopDepth[spl_object_id($visitor->topLevelAppends[$i])] = $depth + 1;
                }

                $label = $currentFile . ' (' . $stmt->name->toString() . ')';
                if ($emit) {
                    GenericMarkerLeakGuard::assertNoLeak($stmt, $label);
                } elseif ($this->diagnostics !== null) {
                    // Call-marker arm only: an un-specialized closure template in a
                    // drained body is always reported elsewhere (seam or orphan check).
                    $leak = GenericMarkerLeakGuard::findLeak($stmt, includeClosureTemplates: false);
                    // Suppress the backstop when the site already carries a diagnostic:
                    // the cloned body preserves the template's line numbers, so a shape
                    // the source seam rejected with a precise error (e.g. a non-concrete
                    // variable turbofish, CODE_UNSPECIALIZED_GENERIC_CLOSURE) would
                    // otherwise double-report here under the vaguer leak code.
                    if ($leak !== null && !$this->alreadyReportedAt($currentFile, $leak->getStartLine())) {
                        $this->diagnostics->add(new Diagnostic(
                            Severity::Error,
                            GenericMarkerLeakGuard::CODE,
                            GenericMarkerLeakGuard::leakMessage($leak, $label),
                            new SourceLocation($currentFile, $leak->getStartLine()),
                        ));
                    }
                }
            }
        } finally {
            // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
            $visitor->markersOnly = false;
        }
    }

    /** The namespace part of an FQN ('' for a global-namespace symbol). */
    private static function namespacePrefixOf(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos === false ? '' : substr($fqn, 0, $pos);
    }

    /**
     * Whether the collector already holds a diagnostic at this exact source position.
     * Used by the drain's check-mode backstop to avoid re-reporting a site the source
     * seam rejected with a more precise code.
     */
    private function alreadyReportedAt(string $file, int $line): bool
    {
        if ($this->diagnostics === null) {
            return false;
        }
        foreach ($this->diagnostics->all() as $diagnostic) {
            if ($diagnostic->location !== null
                && $diagnostic->location->file === $file
                && $diagnostic->location->line === $line
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reject every generic closure/arrow template no call site attempted to
     * specialize. Specialization is call-site-driven: with no in-scope
     * `$var::<...>(...)` grounding its type parameters, the template's
     * type-parameter hints survive into the emitted output as references to
     * non-existent classes (`App\T`) — a guaranteed TypeError behind a clean
     * gate the moment the value is invoked. Handing the closure away as a
     * plain callable cannot ground it (the dispatcher rewrite only exists for
     * turbofish sites), so declared-but-never-specialized is always an error
     * — including when the type parameters are referenced nowhere: the
     * clause is dead syntax, and the remedy is deleting it.
     *
     * Runs in BOTH modes (compile throws at the first orphan; check collects
     * one diagnostic per orphan).
     *
     * @param list<Node\Stmt> $ast
     * @param array<int, true> $attemptedTemplateIds spl_object_id set of
     *        templates some call site looked up (successfully or into one of
     *        the eager rejects — those already carry their own error).
     */
    private function rejectUnspecializedClosureTemplates(array $ast, array $attemptedTemplateIds, string $currentFile): void
    {
        $finder = new \PhpParser\NodeFinder();
        foreach ($finder->find($ast, static fn (Node $n): bool => ($n instanceof Closure || $n instanceof ArrowFunction)
            && is_array($n->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS))) as $template) {
            if (isset($attemptedTemplateIds[spl_object_id($template)])) {
                continue;
            }
            $message = sprintf(
                'Generic %s is declared with type parameters but never specialized: no '
                . '`$var::<...>(...)` call grounds them, so its type-parameter hints would reach '
                . 'the emitted code as references to non-existent classes. Call it with an '
                . 'explicit turbofish, or remove the `<...>` clause.',
                $template instanceof ArrowFunction ? 'arrow function' : 'closure',
            );
            if ($this->diagnostics !== null) {
                $this->diagnostics->add(new Diagnostic(
                    Severity::Error,
                    self::CODE_UNSPECIALIZED_GENERIC_CLOSURE,
                    $message,
                    new SourceLocation($currentFile, $template->getStartLine()),
                ));
                continue;
            }
            throw new RuntimeException($message);
        }
    }

    /**
     * Pass 2: turn each collected dispatch-plan entry into a dispatcher
     * closure plus specialized top-level functions. Skips entries whose
     * argSets are empty -- every reachable declared-but-never-called template
     * was already rejected by rejectUnspecializedClosureTemplates before this
     * pass runs, so an empty argSet here means the template's only call sites
     * drew their own (eager) rejects.
     *
     */
    private function finalizeClosureDispatchers(object $visitor, int $hashLength): void
    {
        $dispatcher = new ClosureDispatcher();
        // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
        foreach ($visitor->closureDispatchPlan as $entry) {
            if ($entry['argSets'] === []) {
                continue;
            }
            $template = $entry['template'];
            $useClauses = $entry['useClauses'];
            $result = $dispatcher->dispatch(
                $template,
                $entry['argSets'],
                $entry['typeParams'],
                $entry['varName'],
                $entry['namespace'],
                $hashLength,
                $useClauses,
            );
            // Replace the original Assign's RHS in place; the AST node's
            // source-position attributes survive so stack traces still
            // point at the user's `$pair = ...` line.
            $entry['assignNode']->expr = $result['assignment']->expr;
            foreach ($result['declarations'] as $specialized) {
                if ($entry['namespaceNode'] !== null) {
                    // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                    $visitor->pendingAppends[] = [$entry['namespaceNode'], $specialized, [
                        'classFqn'  => null,
                        'namespace' => $entry['namespace'],
                    ]];
                } else {
                    // @phpstan-ignore-next-line property.notFound — $visitor is an anonymous class declared above; phpstan can't name its shape.
                    $visitor->topLevelAppends[] = $specialized;
                }
            }
            // Rewrite every recorded call site: prepend the tag arg, clear
            // the turbofish marker. The Variable receiver stays so the
            // dispatcher closure (now in `$varName`) is the call target.
            // Each call site carries its post-padding tag (computed at
            // record time) so empty-turbofish defaults still route to
            // the right specialization arm.
            foreach ($entry['callSites'] as $callSiteEntry) {
                $callSite = $callSiteEntry['node'];
                $tag = $callSiteEntry['tag'];
                $tagArg = new Arg(new String_($tag));
                array_unshift($callSite->args, $tagArg);
                $callSite->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS, null);
                $callSite->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, null);
            }
        }
    }

    /**
     * Whether a generic-method template on `$class` is erasable (`<U : E>`, `U` direct-input only) —
     * in which case it is kept for the Specializer to lower per instantiation rather than stripped.
     */
    private static function isErasableMethodOnClass(ClassMethod $template, ClassLike $class): bool
    {
        $methodParams = $template->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
        $classParams = $class->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        if (!is_array($methodParams) || !is_array($classParams)) {
            return false;
        }
        /** @var list<TypeParam> $methodParams */
        /** @var list<TypeParam> $classParams */
        $classParamNames = array_map(static fn (TypeParam $p): string => $p->name, $classParams);
        return EnclosingBoundErasure::isErasable($template, $methodParams, $classParamNames);
    }

    private function stripMethod(ClassLike $class, string $methodName): void
    {
        $newStmts = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof ClassMethod
                && $stmt->name->toString() === $methodName
                && $stmt->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS) !== null
            ) {
                // Skip — this is the generic-method template; the mangled specializations
                // already live alongside it.
                continue;
            }
            $newStmts[] = $stmt;
        }
        $class->stmts = $newStmts;
    }

    private function stripFunction(Namespace_ $namespace, string $functionName): void
    {
        $newStmts = [];
        foreach ($namespace->stmts as $stmt) {
            if ($stmt instanceof Function_
                && $stmt->name->toString() === $functionName
                && $stmt->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS) !== null
            ) {
                continue;
            }
            $newStmts[] = $stmt;
        }
        $namespace->stmts = $newStmts;
    }

    /**
     * Same shape as `stripFunction` but for the top-level AST when there's no
     * enclosing `namespace { }` block. Returns the filtered statement list so the
     * caller can replace the slot in `$astSet` directly.
     *
     * Does the AST set contain any `FuncCall(name: Variable, ...)` with
     * `ATTR_METHOD_GENERIC_ARGS` attached? Used to keep `process()` from
     * early-returning when the only generic call sites are
     * `$var::<T>(...)` on anonymous closure/arrow templates.
     *
     * @param array<string, list<Node\Stmt>> $astSet
     */
    private static function hasAnonymousGenericCallSite(array $astSet): bool
    {
        $found = false;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($found) extends NodeVisitorAbstract {
            public function __construct(private bool &$found)
            {
            }
            /**
             * @infection-ignore-all -- this pre-scan keeps the rewrite pass alive for files that have no
             * NAMED generic templates but do use anonymous generics. It fires on a variable turbofish
             * call (`$f::<…>()`) AND on ANY generic closure/arrow TEMPLATE — not just assigned ones —
             * so a bare call (`$f('x')`), a return-position or argument-position template, and the
             * never-called orphan are all traversed and diagnosed rather than silently skipped.
             * (Inner anonymous visitor: Infection's blind spot — covered behaviorally by the
             * bare-closure-call and unspecialized-closure accept/reject tests.)
             */
            public function enterNode(Node $node): null
            {
                if ($this->found) {
                    return null;
                }
                if ($node instanceof FuncCall
                    && $node->name instanceof Variable
                    && is_array($node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS))
                ) {
                    $this->found = true;
                }
                if (($node instanceof Closure || $node instanceof ArrowFunction)
                    && is_array($node->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS))
                ) {
                    $this->found = true;
                }
                return null;
            }
        });
        foreach ($astSet as $ast) {
            $traverser->traverse($ast);
            if ($found) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any parameter anywhere declares a `Closure(...)` signature type. Such a
     * parameter is a call-argument conformance target ({@see checkPlainInstanceClosureArgs}
     * / {@see checkPlainStaticClosureArgs}), so the rewrite pass must run to check calls
     * to it even when the program declares no generic method / function / closure.
     *
     * @param array<string, list<Node\Stmt>> $astSet
     */
    private static function hasClosureTypedParameter(array $astSet): bool
    {
        $found = false;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($found) extends NodeVisitorAbstract {
            public function __construct(private bool &$found)
            {
            }

            /**
             * @infection-ignore-all -- inner anonymous visitor (Infection's blind spot);
             * covered behaviorally by the non-generic closure-argument accept/reject fixtures,
             * whose programs declare NO generics, so only this pre-scan keeps the pass alive.
             */
            public function enterNode(Node $node): null
            {
                if (!$this->found
                    && $node instanceof Param
                    && ClosureConformanceValidator::closureSigOf($node->type) !== null
                ) {
                    $this->found = true;
                }
                return null;
            }
        });
        foreach ($astSet as $ast) {
            $traverser->traverse($ast);
            if ($found) {
                return true;
            }
        }
        return false;
    }

    /**
     * Index every free function in the program by its fully-qualified name, whether
     * generic or not (`$functionTemplates` holds only generics). A plain call to a
     * NON-generic free function needs its declared parameters to reach
     * {@see checkPlainFreeFunctionClosureArgs} for closure-argument conformance.
     *
     * @param array<string, list<Node\Stmt>> $astSet
     * @return array<string, Function_>  keyed by namespace\functionName
     */
    private static function indexAllFunctions(array $astSet): array
    {
        $index = [];
        foreach ($astSet as $ast) {
            $visitor = new class extends NodeVisitorAbstract {
                private string $currentNamespace = '';
                /** @var array<string, Function_> */
                public array $functions = [];

                public function enterNode(Node $node): null
                {
                    if ($node instanceof Namespace_) {
                        $this->currentNamespace = $node->name?->toString() ?? '';
                    }
                    if ($node instanceof Function_) {
                        $fqn = $this->currentNamespace !== ''
                            ? $this->currentNamespace . '\\' . $node->name->toString()
                            : $node->name->toString();
                        $this->functions[$fqn] = $node;
                    }
                    return null;
                }
            };
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            foreach ($visitor->functions as $fqn => $fn) {
                $index[$fqn] = $fn;
            }
        }
        return $index;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return list<Node\Stmt>
     */
    private static function stripTopLevelFunction(array $ast, string $functionName): array
    {
        $newStmts = [];
        foreach ($ast as $stmt) {
            if ($stmt instanceof Function_
                && $stmt->name->toString() === $functionName
                && $stmt->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS) !== null
            ) {
                continue;
            }
            $newStmts[] = $stmt;
        }
        return $newStmts;
    }
}
