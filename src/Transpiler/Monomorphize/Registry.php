<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\UnionType;
use RuntimeException;
use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\Diagnostics\Severity;
use XPHP\Diagnostics\SourceLocation;

final class Registry
{
    /** Stable diagnostic codes (machine identifiers tooling can match on). */
    public const CODE_BOUND_VIOLATION = 'xphp.bound_violation';
    public const CODE_MISSING_TYPE_ARGUMENT = 'xphp.missing_type_argument';
    public const CODE_DEFAULT_BOUND_VIOLATION = 'xphp.default_bound_violation';
    public const CODE_UNDEFINED_TEMPLATE = 'xphp.undefined_template';

    /**
     * All specialized classes live under this namespace prefix; the full target FQCN
     * mirrors the original template's namespace and ends with a hash-based class name.
     * Example: App\Containers\Box<App\Models\Plastic> → XPHP\Generated\App\Containers\Box\T_<hash>
     */
    public const GENERATED_NAMESPACE_PREFIX = 'XPHP\\Generated';

    /**
     * Default hash length (in hex chars) of the sha256 digest used in specialized class names.
     * 64 hex chars = full 256-bit digest — no truncation, no birthday-collision risk.
     * Configurable via the XPHP_HASH_LENGTH env var (read at CLI boot).
     */
    public const DEFAULT_HASH_HEX_LENGTH = 64;
    public const MIN_HASH_HEX_LENGTH = 16;
    public const MAX_HASH_HEX_LENGTH = 64;

    /** @var array<string, GenericDefinition> Keyed by template FQN. */
    private array $definitions = [];

    /** @var array<string, GenericInstantiation> Keyed by full generated FQCN. */
    private array $instantiations = [];

    /**
     * @param ?DiagnosticCollector $diagnostics When null (the default, used by `xphp compile`),
     *   validation failures throw as before — byte-identical behavior. When provided (by
     *   `xphp check`), bound violations are appended to the collector and recording continues,
     *   so every error of the validation phase is reported in one run.
     */
    public function __construct(
        private readonly int $hashLength = self::DEFAULT_HASH_HEX_LENGTH,
        private readonly ?TypeHierarchy $hierarchy = null,
        private readonly ?DiagnosticCollector $diagnostics = null,
    ) {
        self::validateHashLength($this->hashLength);
    }

    /**
     * @param list<TypeParam> $typeParams
     */
    public function recordDefinition(
        string $templateFqn,
        string $templateShortName,
        array $typeParams,
        ClassLike $templateAst,
        string $sourceFile,
    ): void {
        if (isset($this->definitions[$templateFqn])) {
            // NB: cross-file duplicate class templates are filtered out earlier by
            // RegistryCollector's `!isAlreadyRecorded()` guard, so this throw is only
            // reachable via the generic-function path. Surfacing duplicate definitions in
            // `xphp check` would require reworking that guard (and would change compile-mode
            // semantics), so it is intentionally NOT part of the collector seam — deferred.
            throw new RuntimeException(sprintf(
                'Generic template "%s" already declared (in %s); duplicate declaration in %s.',
                $templateFqn,
                $this->definitions[$templateFqn]->sourceFile,
                $sourceFile,
            ));
        }

        $this->definitions[$templateFqn] = new GenericDefinition(
            $templateFqn,
            $templateShortName,
            $typeParams,
            $templateAst,
            $sourceFile,
        );
    }

    /**
     * Recursively record an instantiation along with every nested generic sub-instantiation in its args.
     *
     * If the template has defaulted parameters and the supplied args are shorter
     * than the param list, the missing tail is padded with each param's default
     * (substituting earlier args into any type-param refs in the default). The
     * padded tuple feeds both bound validation and the generated-FQN hash, so
     * `Cache::<>` and `Cache::<string, mixed>` produce the same specialization.
     *
     * Detects hash collisions: if a different `(template, args)` pair has already produced the same
     * generated FQCN, throws with a self-contained error message explaining how to raise XPHP_HASH_LENGTH.
     *
     * @param list<TypeRef> $args
     * @param ?SourceLocation $callSite The `.xphp` position of the instantiation site, used to
     *   locate a bound-violation diagnostic in check-mode. Nested sub-instantiations inherit the
     *   enclosing site (they have no distinct source token). Ignored in throw-mode.
     */
    public function recordInstantiation(
        string $templateFqn,
        array $args,
        ?SourceLocation $callSite = null,
    ): GenericInstantiation {
        $args = $this->padWithDefaults($templateFqn, $args, $callSite);

        foreach ($args as $arg) {
            if ($arg->isGeneric()) {
                $this->recordInstantiation($arg->name, $arg->args, $callSite);
            }
        }

        $this->validateBounds($templateFqn, $args, $callSite);

        $generatedFqn = self::generatedFqn($templateFqn, $args, $this->hashLength);
        $template = ltrim($templateFqn, '\\');

        if (isset($this->instantiations[$generatedFqn])) {
            $existing = $this->instantiations[$generatedFqn];
            if (!$this->isSameInstantiation($existing, $template, $args)) {
                throw new RuntimeException($this->collisionMessage($existing, $template, $args, $generatedFqn));
            }
            return $existing;
        }

        $this->instantiations[$generatedFqn] = new GenericInstantiation(
            $template,
            $args,
            $generatedFqn,
        );

        return $this->instantiations[$generatedFqn];
    }

    /**
     * Pad the supplied args with each param's default. Substitutes earlier
     * already-positional args into any type-param references in the default so
     * `class Pair<A, B = A>` instantiated as `<int>` pads to `<int, int>`.
     *
     * Returns the args tuple unchanged when:
     *  - the definition isn't yet recorded (transient case during fixed-point);
     *    arity mismatch surfaces later as the "instantiated but never defined"
     *    error,
     *  - the supplied count already matches or exceeds the param count.
     *
     * Throws when the supplied count is below the leading required params (only
     * trailing defaults can fill).
     *
     * @param list<TypeRef> $args
     * @return list<TypeRef>
     */
    private function padWithDefaults(string $templateFqn, array $args, ?SourceLocation $callSite = null): array
    {
        $definition = $this->definitions[ltrim($templateFqn, '\\')] ?? null;
        if ($definition === null) {
            return $args;
        }
        return self::padArgsWithDefaults(
            $definition->typeParams,
            $args,
            ltrim($templateFqn, '\\'),
            $this->diagnostics,
            $callSite,
        );
    }

    /**
     * Pad an arg list with defaults declared on `$params`, substituting
     * already-positional concretes into any type-param references in the
     * default. Shared between `recordInstantiation` (class/interface/trait
     * templates) and `GenericMethodCompiler` (method/function/closure
     * templates) so the padding semantics stay identical regardless of
     * the call-site shape.
     *
     * When `$diagnostics` is null (compile, and every `GenericMethodCompiler` call) a missing
     * non-defaulted param throws as before. With a collector (check) it appends a Diagnostic and
     * returns the partial padding gathered so far, so the run continues to surface other errors.
     * Returns `$args` unchanged when the supplied count already matches or exceeds the param count.
     *
     * @param list<TypeParam> $params
     * @param list<TypeRef> $args
     * @return list<TypeRef>
     */
    public static function padArgsWithDefaults(
        array $params,
        array $args,
        string $templateLabel,
        ?DiagnosticCollector $diagnostics = null,
        ?SourceLocation $callSite = null,
    ): array {
        $supplied = count($args);
        $needed = count($params);
        if ($supplied >= $needed) {
            return $args;
        }

        $padded = $args;
        for ($i = $supplied; $i < $needed; $i++) {
            if ($params[$i]->default === null) {
                $message = self::missingTypeArgumentMessage($templateLabel, $supplied, $params[$i]->name, $i + 1);
                if ($diagnostics !== null) {
                    $diagnostics->add(new Diagnostic(
                        Severity::Error,
                        self::CODE_MISSING_TYPE_ARGUMENT,
                        $message,
                        $callSite,
                    ));

                    return $padded;
                }
                throw new RuntimeException($message);
            }
            $subst = [];
            foreach ($padded as $j => $concrete) {
                $subst[$params[$j]->name] = $concrete;
            }
            $padded[] = Specializer::substituteTypeRef($params[$i]->default, $subst);
        }
        return $padded;
    }

    /**
     * Single source of truth for the missing-required-type-argument message.
     */
    private static function missingTypeArgumentMessage(
        string $templateLabel,
        int $supplied,
        string $paramName,
        int $position,
    ): string {
        return sprintf(
            'Generic template "%s" was instantiated with %d type argument(s) '
            . 'but parameter `%s` (position %d) has no default; supply it '
            . 'explicitly or add defaults to every preceding required parameter.',
            $templateLabel,
            $supplied,
            $paramName,
            $position,
        );
    }

    /**
     * Declaration-time bound check on fully-concrete defaults. Defaults that
     * reference earlier type-params can't be checked here because the bound
     * verdict depends on the concrete arg supplied at the call site -- those
     * are checked by the existing per-instantiation `validateBounds` path
     * after `padWithDefaults` substitutes the concretes in.
     *
     * Runs after definitions are collected but before instantiations are
     * recorded, so a bad declaration fails the compile at the source-level
     * before any padded instantiation amplifies the error.
     */
    public function validateDefaultsAgainstBounds(): void
    {
        if ($this->hierarchy === null) {
            return;
        }
        foreach ($this->definitions as $definition) {
            foreach ($definition->typeParams as $param) {
                if ($param->bound === null || $param->default === null) {
                    continue;
                }
                if (!$param->default->isConcrete()) {
                    continue;
                }
                $verdict = self::evaluateBound(
                    $param->bound,
                    $param->default,
                    $this->hierarchy,
                );
                if ($verdict === true) {
                    continue;
                }
                $boundDisplay = self::formatBound($param->bound);
                $defaultDisplay = $param->default->toDisplayString();
                $reason = $verdict === false
                    ? sprintf('does not satisfy "%s".', $boundDisplay)
                    : sprintf(
                        'is not in the source set the hierarchy was built from (and is '
                        . 'not a recognized PHP built-in), so the compiler cannot prove '
                        . 'it satisfies "%s".',
                        $boundDisplay,
                    );
                $message = self::defaultBoundViolationMessage(
                    $param->name,
                    $definition->templateFqn,
                    $boundDisplay,
                    $defaultDisplay,
                    $reason,
                );
                if ($this->diagnostics !== null) {
                    $this->diagnostics->add(new Diagnostic(
                        Severity::Error,
                        self::CODE_DEFAULT_BOUND_VIOLATION,
                        $message,
                        new SourceLocation($definition->sourceFile, $definition->templateAst->getStartLine()),
                    ));
                    continue;
                }
                throw new RuntimeException($message);
            }
        }
    }

    /**
     * Report every recorded instantiation whose template was never defined. In `xphp compile`
     * this surfaces as a thrown error inside the specialization loop; `xphp check` doesn't run
     * that loop, so it detects the same condition here by comparing recorded instantiations
     * against the definition set. No source location is attached — the instantiation does not
     * retain its call site — but the message names the template and its generated FQCN.
     */
    public function collectUndefinedTemplates(DiagnosticCollector $diagnostics): void
    {
        foreach ($this->instantiations as $generatedFqn => $instantiation) {
            if (!isset($this->definitions[$instantiation->templateFqn])) {
                $diagnostics->add(new Diagnostic(
                    Severity::Error,
                    self::CODE_UNDEFINED_TEMPLATE,
                    self::undefinedTemplateMessage($instantiation->templateFqn, $generatedFqn),
                ));
            }
        }
    }

    /**
     * Single source of truth for the "instantiated but never defined" message, shared by the
     * compile-time throw (Compiler) and the check-time diagnostic.
     */
    public static function undefinedTemplateMessage(string $templateFqn, string $generatedFqn): string
    {
        return sprintf(
            'Generic template "%s" was instantiated but never defined (generated as: %s).',
            $templateFqn,
            $generatedFqn,
        );
    }

    /**
     * Single source of truth for the default-violates-bound message.
     */
    private static function defaultBoundViolationMessage(
        string $paramName,
        string $templateFqn,
        string $boundDisplay,
        string $defaultDisplay,
        string $reason,
    ): string {
        return sprintf(
            "Default for generic parameter `%s` of \"%s\" violates the parameter's bound.\n"
            . "  bound:   %s\n"
            . "  default: %s\n"
            . "  reason:  %s",
            $paramName,
            $templateFqn,
            $boundDisplay,
            $defaultDisplay,
            $reason,
        );
    }

    /**
     * Variance-position check over every collected definition (moved out of the parser so
     * `xphp check` can collect all violations across files in one run). Delegates to
     * {@see VariancePositionValidator}: with this Registry's collector it gathers diagnostics
     * at each offending member; without one (compile) it throws the first violation.
     *
     * @return list<string> Template FQNs that had at least one position violation, so the
     *   inner-variance pass can skip them (the issue is already reported) — mirroring
     *   compile-mode, where the position check fails fast before inner-variance runs.
     */
    public function validateVariancePositions(): array
    {
        $flagged = [];
        foreach ($this->definitions as $templateFqn => $definition) {
            $hadViolation = VariancePositionValidator::assertPositions(
                $definition->templateAst,
                $definition->typeParams,
                $this->diagnostics,
                $definition->sourceFile,
            );
            if ($hadViolation) {
                $flagged[] = $templateFqn;
            }
        }

        return $flagged;
    }

    /**
     * Undeclared-type check over every collected definition (delegates to
     * {@see UndeclaredTypeParameterValidator}): a generic member naming a type
     * that is neither a declared type parameter nor a known type — e.g. the `T`
     * in `interface Foo<Z> { add(T $x): void; }`. With this Registry's collector
     * it gathers every finding (each at the offending member); without one
     * (compile) it throws the first. Skipped when no hierarchy was attached
     * (bare-Registry tests have nothing to resolve names against).
     */
    public function validateUndeclaredTypeParameters(): void
    {
        if ($this->hierarchy === null) {
            return;
        }

        foreach ($this->definitions as $templateFqn => $definition) {
            UndeclaredTypeParameterValidator::assert(
                $definition->templateAst,
                $templateFqn,
                $this->hierarchy,
                $this->diagnostics,
                $definition->sourceFile,
            );
        }
    }

    /**
     * Inner-template variance composition check over every collected definition (delegates to
     * {@see InnerVarianceValidator}). With this Registry's collector it gathers every violation
     * (each located at the offending member); without one (compile) it throws the first.
     *
     * @param list<string> $skipTemplateFqns Definitions already flagged by the position check;
     *   skipped here so the same `+T`/`-T` misuse isn't reported by both passes.
     */
    public function validateInnerVariance(array $skipTemplateFqns = []): void
    {
        foreach ($this->definitions as $templateFqn => $definition) {
            if (in_array($templateFqn, $skipTemplateFqns, true)) {
                continue;
            }
            InnerVarianceValidator::assertComposition(
                $definition,
                $this->definitions,
                $this->diagnostics,
                $definition->sourceFile,
            );
        }
    }

    /**
     * Enforce per-param bounds on a concrete instantiation. Fires before the FQCN is hashed
     * so the error message points at the SOURCE-level violation, not the obfuscated `T_<hash>`.
     *
     * Strategy: for each (TypeParam with bound, concrete TypeRef) pair, ask the hierarchy
     * whether the concrete arg satisfies the bound. The hierarchy returns:
     *   - true:  proven subtype — accept.
     *   - false: proven non-subtype (also the answer for scalars vs class bounds) — reject.
     *   - null:  unknown — reject too, with a different message, so users can either widen
     *            the bound or add the type into the source set the hierarchy was built from.
     *
     * The whole validation is skipped if no hierarchy was attached (e.g., bare-Registry tests
     * that don't care about bounds) or if no definition is on file for the template yet —
     * the latter happens transiently during fixed-point specialization; the next pass picks
     * the definition up.
     *
     * @param list<TypeRef> $args
     */
    private function validateBounds(string $templateFqn, array $args, ?SourceLocation $callSite = null): void
    {
        if ($this->hierarchy === null) {
            return;
        }
        $definition = $this->definitions[ltrim($templateFqn, '\\')] ?? null;
        if ($definition === null) {
            return;
        }
        self::checkBounds(
            $definition->typeParams,
            $args,
            $this->hierarchy,
            self::formatInstantiation(ltrim($templateFqn, '\\'), $args),
            $this->diagnostics,
            $callSite,
        );
    }

    /**
     * Reusable bound check for any (typeParams, concreteArgs) pair against a hierarchy.
     *
     * Used both by `validateBounds` (class/interface instantiation) and by
     * `GenericMethodCompiler` (method/function-level type-param bounds at call-site time).
     * Single home for the verdict-formatting + error-shape contract so the user-facing
     * error message looks the same regardless of where the violation surfaces.
     *
     * `$instantiationLabel` is the human-readable context string that opens the error
     * (e.g. `"App\Box<int>"` or `"App\Util::identity<int>"`).
     *
     * When `$diagnostics` is null (the default — `xphp compile`, and every `GenericMethodCompiler`
     * call site) a violation throws as before (byte-identical message). When a collector is
     * supplied (`xphp check`), each violation is appended as a `Diagnostic` and the loop continues,
     * so all violating parameters of one instantiation are reported in a single run.
     *
     * @param list<TypeParam> $typeParams
     * @param list<TypeRef> $args
     */
    public static function checkBounds(
        array $typeParams,
        array $args,
        TypeHierarchy $hierarchy,
        string $instantiationLabel,
        ?DiagnosticCollector $diagnostics = null,
        ?SourceLocation $callSite = null,
    ): void {
        // Arity mismatch is a different error class (caught upstream); skip silently here
        // so that the existing pipeline can produce the more specific message.
        if (count($typeParams) !== count($args)) {
            return;
        }
        foreach ($typeParams as $i => $param) {
            if ($param->bound === null) {
                continue;
            }
            $concrete = $args[$i];
            $verdict = self::evaluateBound($param->bound, $concrete, $hierarchy);
            if ($verdict === true) {
                continue;
            }
            $boundDisplay = self::formatBound($param->bound);
            // Single-leaf bounds keep the original "extend/implement" wording
            // (the relationship is a direct extends/implements query against the
            // class hierarchy). Compound bounds (intersection / union / DNF)
            // can't be described that way, so they get "satisfy" instead.
            $isSimpleLeaf = $param->bound instanceof BoundLeaf;
            $detail = $verdict === false
                ? ($isSimpleLeaf
                    ? sprintf('"%s" does not extend/implement "%s".', $concrete->toDisplayString(), $boundDisplay)
                    : sprintf('"%s" does not satisfy "%s".', $concrete->toDisplayString(), $boundDisplay))
                : sprintf(
                    '"%s" is not in the source set the hierarchy was built from (and is not a recognized PHP built-in, or its bound satisfaction comes via a trait the compiler does not yet follow), so the compiler cannot prove it satisfies "%s".',
                    $concrete->toDisplayString(),
                    $boundDisplay,
                );
            $message = self::boundViolationMessage(
                $instantiationLabel,
                $param->name,
                $boundDisplay,
                $concrete->toDisplayString(),
                $detail,
            );
            if ($diagnostics !== null) {
                $diagnostics->add(new Diagnostic(Severity::Error, self::CODE_BOUND_VIOLATION, $message, $callSite));
                continue;
            }
            throw new RuntimeException($message);
        }
    }

    /**
     * Build the user-facing bound-violation message. Single source of truth shared by the
     * throw path (`xphp compile`) and the diagnostic path (`xphp check`) so the two can never
     * drift — the exact text is pinned by `expectExceptionMessage` assertions.
     */
    private static function boundViolationMessage(
        string $instantiationLabel,
        string $paramName,
        string $boundDisplay,
        string $concreteDisplay,
        string $detail,
    ): string {
        return sprintf(
            "Generic bound violated while instantiating %s.\n"
            . "  type parameter %s is bounded by %s\n"
            . "  but the supplied concrete type is %s\n\n"
            . "  %s",
            $instantiationLabel,
            $paramName,
            $boundDisplay,
            $concreteDisplay,
            $detail,
        );
    }

    /**
     * Three-way verdict (true / false / null) for a bound expression against a
     * concrete TypeRef. Walks the BoundExpr tree:
     *   - Leaf:        delegates to `$hierarchy->isSubtype` using the leaf's
     *                  name. Any generic args on the leaf (`Comparable<T>` in
     *                  an F-bounded shape) are intentionally NOT substituted
     *                  or consulted -- the hierarchy operates on erased
     *                  nominal subtyping, so `MyType extends Comparable<MyType>?`
     *                  reduces to `MyType extends Comparable?`. This is
     *                  consistent across the decl-time `validateDefaultsAgainstBounds`
     *                  call site and the inst-time `checkBounds` call site.
     *   - Intersection: any false -> false; all true -> true; otherwise null.
     *   - Union:        any true -> true; all false -> false; otherwise null.
     */
    private static function evaluateBound(BoundExpr $bound, TypeRef $concrete, TypeHierarchy $hierarchy): ?bool
    {
        if ($bound instanceof BoundLeaf) {
            return $hierarchy->isSubtype($concrete->name, $bound->type->name);
        }
        if ($bound instanceof BoundIntersection) {
            $sawNull = false;
            foreach ($bound->operands as $operand) {
                $v = self::evaluateBound($operand, $concrete, $hierarchy);
                if ($v === false) {
                    return false;
                }
                if ($v === null) {
                    $sawNull = true;
                }
            }
            return $sawNull ? null : true;
        }
        if ($bound instanceof BoundUnion) {
            $sawNull = false;
            foreach ($bound->operands as $operand) {
                $v = self::evaluateBound($operand, $concrete, $hierarchy);
                if ($v === true) {
                    return true;
                }
                if ($v === null) {
                    $sawNull = true;
                }
            }
            return $sawNull ? null : false;
        }
        // Defensive: BoundExpr is an abstract base and we own every subtype.
        // Unreachable in any test, but keep the return shape consistent.
        return null;
    }

    /**
     * Render a bound expression in source-form for error messages:
     *   - Leaf            -> the bare FQN
     *   - Intersection    -> "A & B & C"
     *   - Union           -> "A | B | C"
     *   - DNF             -> "(A & B) | C"  (parens around inner intersections)
     */
    private static function formatBound(BoundExpr $bound): string
    {
        if ($bound instanceof BoundLeaf) {
            return $bound->type->name;
        }
        if ($bound instanceof BoundIntersection) {
            // Symmetric to the Union branch below: when an inner operand is
            // a Union (`(A | B) & C`), wrap it in parens so the rendered
            // bound reflects PHP's & > | precedence convention. Without the
            // wrap, `BoundIntersection(BoundUnion(A, B), C)` renders as
            // `A | B & C` which a reader parses as `A | (B & C)` -- the
            // wrong shape.
            return implode(' & ', array_map(
                static fn (BoundExpr $op): string => $op instanceof BoundUnion
                    ? '(' . self::formatBound($op) . ')'
                    : self::formatBound($op),
                $bound->operands,
            ));
        }
        if ($bound instanceof BoundUnion) {
            return implode(' | ', array_map(
                static fn (BoundExpr $op): string => $op instanceof BoundIntersection
                    ? '(' . self::formatBound($op) . ')'
                    : self::formatBound($op),
                $bound->operands,
            ));
        }
        return '<unknown bound>';
    }

    /**
     * @param list<TypeRef> $args
     */
    private function isSameInstantiation(GenericInstantiation $existing, string $template, array $args): bool
    {
        if (ltrim($existing->templateFqn, '\\') !== $template) {
            return false;
        }
        if (count($existing->concreteTypes) !== count($args)) {
            return false;
        }
        return self::canonicalArgList($existing->concreteTypes) === self::canonicalArgList($args);
    }

    /**
     * @param list<TypeRef> $args
     */
    private static function canonicalArgList(array $args): string
    {
        return implode('|', array_map(static fn (TypeRef $r): string => $r->canonical(), $args));
    }

    /**
     * @param list<TypeRef> $args
     */
    private function collisionMessage(
        GenericInstantiation $existing,
        string $template,
        array $args,
        string $generatedFqn,
    ): string {
        $suggested = min($this->hashLength * 2, self::MAX_HASH_HEX_LENGTH);
        if ($suggested <= $this->hashLength) {
            $suggested = self::MAX_HASH_HEX_LENGTH;
        }

        return sprintf(
            "Hash collision detected while monomorphizing generics.\n\n"
            . "Two distinct instantiations produced the same specialized FQCN:\n"
            . "  existing : %s\n"
            . "  new      : %s\n"
            . "  collision: %s\n\n"
            . "The current XPHP_HASH_LENGTH = %d is too short for this codebase.\n"
            . "Increase it (max %d, the full sha256 digest) and re-run, e.g.:\n\n"
            . "    XPHP_HASH_LENGTH=%d bin/xphp compile <source> <target> <cache>\n",
            self::formatInstantiation($existing->templateFqn, $existing->concreteTypes),
            self::formatInstantiation($template, $args),
            $generatedFqn,
            $this->hashLength,
            self::MAX_HASH_HEX_LENGTH,
            $suggested,
        );
    }

    /**
     * @param list<TypeRef> $args
     */
    private static function formatInstantiation(string $template, array $args): string
    {
        if ($args === []) {
            return $template;
        }
        $inner = implode(', ', array_map(static fn (TypeRef $r): string => $r->toDisplayString(), $args));
        return $template . '<' . $inner . '>';
    }

    /**
     * @return array<string, GenericDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function definition(string $templateFqn): ?GenericDefinition
    {
        return $this->definitions[$templateFqn] ?? null;
    }

    /**
     * @return array<string, GenericInstantiation>
     */
    public function instantiations(): array
    {
        return $this->instantiations;
    }

    /**
     * @return array{definitions: list<array{name: string, typeParams: list<string>, sourceFile: string}>, instantiations: list<array{template: string, concreteTypes: list<string>, generatedFqn: string}>}
     */
    public function toArray(): array
    {
        $defs = [];
        foreach ($this->definitions as $def) {
            $defs[] = [
                'name'       => $def->templateFqn,
                'typeParams' => $def->typeParamNames(),
                'sourceFile' => $def->sourceFile,
            ];
        }

        $inst = [];
        foreach ($this->instantiations as $i) {
            $inst[] = [
                'template'      => $i->templateFqn,
                'concreteTypes' => array_map(static fn (TypeRef $r): string => $r->toDisplayString(), $i->concreteTypes),
                'generatedFqn'  => $i->generatedFqn,
            ];
        }

        return ['definitions' => $defs, 'instantiations' => $inst];
    }

    /**
     * Compute the full target FQCN for a specialized class.
     *
     * Layout: XPHP\Generated\<template-fqcn>\T_<hash>
     *   where <hash> is the first $hashLength hex chars of sha256 over the canonical arg-list string.
     *
     * Two examples illustrate why this is collision-safe:
     *   - App\Containers\Box<App\Models\Plastic>  →  XPHP\Generated\App\Containers\Box\T_<h1>
     *   - App\Other\Box<App\Models\Plastic>       →  XPHP\Generated\App\Other\Box\T_<h1>   (same hash; different namespace)
     *   - App\Containers\Box<App\Other\Plastic>   →  XPHP\Generated\App\Containers\Box\T_<h2>  (same namespace; different hash)
     *
     * @param list<TypeRef> $args
     */
    public static function generatedFqn(
        string $templateFqn,
        array $args,
        int $hashLength = self::DEFAULT_HASH_HEX_LENGTH,
    ): string {
        $template = ltrim($templateFqn, '\\');

        return self::GENERATED_NAMESPACE_PREFIX . '\\' . $template . '\\T_' . self::canonicalHash($args, $hashLength);
    }

    /**
     * Canonical-argument-list hex hash, used to name both specialized classes (FQCN suffix)
     * and specialized methods/functions (mangled-name suffix). Single home for the hashing
     * logic so future tweaks (e.g. shorter hash for methods) live in one place.
     *
     * @param list<TypeRef> $args
     */
    public static function canonicalHash(array $args, int $hashLength = self::DEFAULT_HASH_HEX_LENGTH): string
    {
        self::validateHashLength($hashLength);
        $canonical = implode('|', array_map(static fn (TypeRef $r): string => $r->canonical(), $args));
        return substr(hash('sha256', $canonical), 0, $hashLength);
    }

    /**
     * Read XPHP_HASH_LENGTH from the environment, falling back to the default.
     * Throws on garbage values (non-numeric, out of range) so misconfiguration fails loud at boot.
     */
    public static function resolveHashLengthFromEnv(): int
    {
        $raw = getenv('XPHP_HASH_LENGTH');
        if ($raw === false || $raw === '') {
            return self::DEFAULT_HASH_HEX_LENGTH;
        }
        if (!ctype_digit($raw)) {
            throw new InvalidArgumentException(sprintf(
                'XPHP_HASH_LENGTH must be a positive integer; got "%s".',
                $raw,
            ));
        }
        $length = (int) $raw;
        self::validateHashLength($length);
        return $length;
    }

    private static function validateHashLength(int $length): void
    {
        if ($length < self::MIN_HASH_HEX_LENGTH || $length > self::MAX_HASH_HEX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Hash length must be between %d and %d (sha256 hex output); got %d.',
                self::MIN_HASH_HEX_LENGTH,
                self::MAX_HASH_HEX_LENGTH,
                $length,
            ));
        }
    }
}
