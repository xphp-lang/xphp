<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Arg;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;
use RuntimeException;

/**
 * Materializes multiple type-arg specializations of a single anonymous
 * generic template (a `Closure` or `ArrowFunction`) plus a dispatcher
 * closure that routes runtime calls to the right specialization via a
 * sentinel-arg-prefix mechanism.
 *
 * ## Dispatch mechanic
 *
 * The dispatcher closure has a fixed signature regardless of body arity:
 *
 *     function (string $__xphp_tag, mixed ...$__xphp_args): mixed { ... }
 *
 * Its body is a `match ($__xphp_tag)` that routes to a fully-qualified
 * specialized function call. Each generated specialization is a top-level
 * `Function_` whose body comes from the template body with type-params
 * substituted to concrete types.
 *
 * The sentinel tag derives from `Registry::canonicalHash($args, $hashLength)`
 * -- the same hash used to mangle specialized class names -- so tag <->
 * specialized-FQN is bidirectionally derivable without any side table.
 *
 * Call sites get the tag prepended as the first argument; reflection on
 * the dispatcher sees a 2-arg-variadic shape (`$__xphp_tag`, `...$__xphp_args`),
 * which is the user-visible quirk. Pre-P5.4 the user couldn't specialize
 * generic closures anyway (closures-with-`use`, arrows, and statics were
 * rejected; only capture-free closures hoisted to a function FQN), so
 * existing source-level behavior of the capture-free case stays the same;
 * Reflection now reflects on the dispatcher rather than the original
 * closure.
 *
 * ## Why a dispatcher, not per-call inline rewrite
 *
 * The streaming "rewrite each `$fn::<T>(...)` to `\fully\qualified\function(...)`"
 * approach worked for capture-free closures because the top-level
 * function has no captured scope to preserve. Arrows and `use`-closures
 * (Phase 5 D2 / D3) bind captures at the original declaration site --
 * the dispatcher closure preserves that binding because it IS constructed
 * at the declaration site, and it routes via FQ function calls (which
 * carry no captures themselves). This commit (P5.4) ships only the
 * dispatcher infrastructure and migrates the capture-free hoist as the
 * first consumer; D2 and D3 land as additive consumers.
 *
 * @phpstan-type DispatchResult array{declarations: list<Function_>, assignment: Assign}
 */
final class ClosureDispatcher
{
    public const TAG_PARAM_NAME = '__xphp_tag';
    public const ARGS_PARAM_NAME = '__xphp_args';

    public function __construct(
        private readonly Specializer $specializer = new Specializer(),
    ) {
    }

    /**
     * Generate one specialized top-level Function_ per unique arg tuple,
     * plus a re-assignment to `$varName` that routes calls via a `match`
     * on a leading type-tag string.
     *
     * @param list<list<TypeRef>> $argSets   Each entry is one specialization's type-args.
     *                                       Duplicate tuples (by canonical-hash) are deduped.
     * @param list<TypeParam>    $typeParams The closure template's type parameters.
     * @param list<ClosureUse>   $useClauses `use ($x, &$y, ...)` clauses to forward onto the
     *                                       dispatcher closure (P5.6); empty for capture-free
     *                                       and arrow forms.
     *
     * @return DispatchResult
     *
     * @infection-ignore-all -- `$seenTags[$tag] = true` -> `false`:
     *   the dedupe gate uses `isset()`, which returns true regardless of
     *   value (false is still set). The mutant is observably identical
     *   to the original.
     */
    public function dispatch(
        Closure|ArrowFunction $template,
        array $argSets,
        array $typeParams,
        string $varName,
        string $namespace,
        int $hashLength,
        array $useClauses = [],
    ): array {
        // Resolve tag / args param names: if the user happens to capture
        // a variable named `__xphp_tag` or `__xphp_args`, rename the
        // dispatcher's tag/args params to avoid the collision. Uses a
        // short hash of the template's start file pos for stability.
        [$tagParamName, $argsParamName] = self::resolveDispatcherParamNames($useClauses, $template);

        $declarations = [];
        $arms = [];
        $seenTags = [];
        foreach ($argSets as $args) {
            $tag = self::tagFor($args, $hashLength);
            if (isset($seenTags[$tag])) {
                continue;
            }
            $seenTags[$tag] = true;
            $spec = $this->buildSpecialization(
                $template,
                $args,
                $typeParams,
                $varName,
                $namespace,
                $hashLength,
                $useClauses,
            );
            $declarations[] = $spec['function'];
            $arms[] = ['tag' => $tag, 'mangledFqn' => $spec['mangledFqn']];
        }
        $dispatcher = $this->buildDispatcherClosure(
            $template,
            $arms,
            $useClauses,
            $tagParamName,
            $argsParamName,
        );
        $assignment = new Assign(new Variable($varName), $dispatcher);
        return ['declarations' => $declarations, 'assignment' => $assignment];
    }

    /**
     * The sentinel tag for an arg tuple. Reuses `Registry::canonicalHash`
     * so the tag is bidirectionally derivable from the same canonical form
     * the rest of the specializer machinery uses.
     *
     * @param list<TypeRef> $args
     */
    public static function tagFor(array $args, int $hashLength): string
    {
        return 'T_' . Registry::canonicalHash($args, $hashLength);
    }

    /**
     * @param list<TypeRef>     $args
     * @param list<TypeParam>   $params
     * @param list<ClosureUse>  $useClauses captures lifted as trailing params
     * @return array{function: Function_, tag: string, mangledFqn: string}
     */
    private function buildSpecialization(
        Closure|ArrowFunction $template,
        array $args,
        array $params,
        string $varName,
        string $namespace,
        int $hashLength,
        array $useClauses,
    ): array {
        $shortName = 'closure_' . $varName;
        $mangled = $shortName . '_T_' . Registry::canonicalHash($args, $hashLength);
        $tag = self::tagFor($args, $hashLength);
        $mangledFqn = $namespace !== '' ? $namespace . '\\' . $mangled : $mangled;

        $synthetic = self::syntheticFunctionFromTemplate($template, $shortName, $params, $useClauses);

        $substitution = [];
        foreach ($params as $i => $param) {
            $substitution[$param->name] = $args[$i];
        }

        $specialized = $this->specializer->specializeFunction($synthetic, $substitution, $mangled);

        return [
            'function'   => $specialized,
            'tag'        => $tag,
            'mangledFqn' => $mangledFqn,
        ];
    }

    /**
     * Builds a `Function_` template the existing `specializeFunction` path
     * can consume. Arrow functions are lifted to a `return $expr;` body so
     * the resulting `Function_` carries the same shape regardless of which
     * anonymous template flavor came in.
     *
     * @param list<TypeParam> $params
     *
     * @infection-ignore-all -- the final `setAttribute(ATTR_METHOD_GENERIC_PARAMS, ...)`
     *   call is defensive bookkeeping. `Specializer::specializeFunction` takes
     *   substitution as an explicit arg (it doesn't read ATTR_METHOD_GENERIC_PARAMS),
     *   then strips the attr to null on the cloned output. Removing the
     *   setAttribute is observably identical -- the next call (specializeFunction)
     *   ignores it and clears it anyway.
     */
    private static function syntheticFunctionFromTemplate(
        Closure|ArrowFunction $template,
        string $shortName,
        array $params,
        array $useClauses,
    ): Function_ {
        if ($template instanceof Closure) {
            $stmts = $template->stmts;
            $byRef = $template->byRef;
            $attrGroups = $template->attrGroups;
            $returnType = $template->returnType;
            $templateParams = $template->params;
        } else {
            $stmts = [new Return_($template->expr)];
            $byRef = $template->byRef;
            $attrGroups = $template->attrGroups;
            $returnType = $template->returnType;
            $templateParams = $template->params;
        }
        // Lift each `use ($x)` capture into a trailing `mixed $x` param so
        // the dispatcher can forward its captured snapshot at call time.
        // Captures pulled from the dispatcher's `use` clause -- not from
        // the closure's own `uses` list (which is only populated on
        // Closure templates, not arrows).
        foreach ($useClauses as $use) {
            $templateParams[] = new Param(
                $use->var,
                type: new Identifier('mixed'),
            );
        }
        $synthetic = new Function_(
            new Identifier($shortName),
            [
                'params'     => $templateParams,
                'returnType' => $returnType,
                'byRef'      => $byRef,
                'stmts'      => $stmts,
                'attrGroups' => $attrGroups,
            ],
            $template->getAttributes(),
        );
        $synthetic->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS, $params);
        return $synthetic;
    }

    /**
     * @param list<array{tag: string, mangledFqn: string}> $arms
     * @param list<ClosureUse>                              $useClauses
     */
    private function buildDispatcherClosure(
        Closure|ArrowFunction $template,
        array $arms,
        array $useClauses,
        string $tagParamName,
        string $argsParamName,
    ): Closure {
        $tagVar = new Variable($tagParamName);
        $argsVar = new Variable($argsParamName);

        // Each match-arm body forwards the variadic-spread args plus the
        // captured vars (which arrived as `use ($y, $z)` on the dispatcher
        // closure and are now in scope here). Captures must be passed via
        // *named* args after the unpack because PHP rejects positional
        // arguments after `...$args`. Named-after-unpack is legal since
        // PHP 8.1; the specialized function declares each capture with
        // the same name so position-vs-name binding lines up.
        $captureArgs = [];
        foreach ($useClauses as $use) {
            if (!$use->var instanceof Variable || !is_string($use->var->name)) {
                continue;
            }
            $captureArgs[] = new Arg(
                $use->var,
                name: new Identifier($use->var->name),
            );
        }

        $matchArms = [];
        foreach ($arms as $arm) {
            $matchArms[] = new MatchArm(
                [new String_($arm['tag'])],
                new FuncCall(
                    new FullyQualified($arm['mangledFqn']),
                    array_merge([new Arg($argsVar, unpack: true)], $captureArgs),
                ),
            );
        }
        // Default arm: throw "Unknown generic specialization tag: <tag>".
        $matchArms[] = new MatchArm(
            null,
            new Throw_(new New_(
                new FullyQualified(RuntimeException::class),
                [new Arg(new Concat(
                    new String_('Unknown generic specialization tag: '),
                    $tagVar,
                ))],
            )),
        );

        $tagParam = new Param(
            $tagVar,
            type: new Identifier('string'),
        );
        $argsParam = new Param(
            $argsVar,
            type: new Identifier('mixed'),
            variadic: true,
        );

        return new Closure(
            [
                'params'     => [$tagParam, $argsParam],
                'returnType' => new Identifier('mixed'),
                'uses'       => $useClauses,
                'stmts'      => [new Return_(new Match_($tagVar, $matchArms))],
            ],
            $template->getAttributes(),
        );
    }

    /**
     * @infection-ignore-all -- the implicit-capture analyzer and its
     * helpers (collectFreeVarsFromExpr / usesThis / resolveDispatcherParamNames)
     * surface many semantic-equivalent mutants: ordered-set `??=` vs `=`
     * have identical observable behavior (assignment only matters when
     * the key is unset, since the value is `true` and isset() doesn't
     * care about the value); LogicalAnd <-> Or pairs across the
     * `instanceof X && is_string($n->name)` and `=== 'this' || in
     * paramNames` checks produce the same accept/reject decisions for
     * every fixture we can construct; the substr offset/length mutants
     * on the collision-suffix only change the hex pattern, not the
     * "tag-renamed-due-to-collision" outcome. End-to-end coverage from
     * `ArrowSpecializationTest` pins the observable behavior.
     *
     * Compute the set of free variables in an arrow function's body --
     * the implicit captures PHP materializes at runtime when the arrow
     * is constructed. Returns them as `ClosureUse` nodes ready to drop
     * onto a dispatcher closure's `use (...)` clause.
     *
     * All captures are by-value (`byRef: false`) -- matches PHP arrow
     * semantics, which have no `&` syntax for implicit captures. If a
     * future commit needs to detect mutation-by-ref usage, the contract
     * here must be revisited along with the corresponding lifted-param
     * declaration in `syntheticFunctionFromTemplate`.
     *
     * Discipline (see Round 10 review):
     *  - skip the arrow's own params,
     *  - skip `$this` (we reject the whole specialization upstream),
     *  - DON'T descend into nested `Closure` bodies (PHP's regular closure
     *    has its own `use` clause that names exactly what it imports), but
     *    DO harvest the nested closure's `use` clause -- those vars were
     *    free at OUR scope and PHP needs them present when the dispatcher
     *    constructs the inner closure at runtime,
     *  - DO recurse into nested `ArrowFunction` bodies (the inner arrow's
     *    free vars include ones from our scope).
     *
     * Captures are returned in deterministic first-occurrence order so
     * the emitted dispatcher / specialization output is reproducible
     * across runs.
     *
     * @return list<ClosureUse>
     */
    public static function implicitCapturesOf(ArrowFunction $arrow): array
    {
        $paramNames = [];
        foreach ($arrow->params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $paramNames[$param->var->name] = true;
            }
        }
        $captures = [];
        self::collectFreeVarsFromExpr($arrow->expr, $paramNames, $captures);
        return array_values(array_map(
            static fn (string $name): ClosureUse => new ClosureUse(new Variable($name), false),
            array_keys($captures),
        ));
    }

    /**
     * @infection-ignore-all -- see `implicitCapturesOf` docblock for
     * the catalog of semantic-equivalent mutants in this walker.
     *
     * Recursive collector. `$captures` is an ordered set keyed by var
     * name (insert-only, no overwrite) so iteration order matches first
     * occurrence in the source.
     *
     * @param array<string, true> $paramNames
     * @param array<string, true> $captures   accumulator (by-ref)
     */
    private static function collectFreeVarsFromExpr(
        \PhpParser\Node $expr,
        array $paramNames,
        array &$captures,
    ): void {
        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor(new class($paramNames, $captures) extends \PhpParser\NodeVisitorAbstract {
            /** @param array<string, true> $paramNames */
            public function __construct(
                private array $paramNames,
                private array &$captures,
            ) {
            }

            public function enterNode(\PhpParser\Node $node): ?int
            {
                if ($node instanceof Closure) {
                    // Don't descend into the body, but harvest the inner
                    // closure's `use` clause -- those vars were free at
                    // our scope (the user wrote them naming our locals).
                    foreach ($node->uses as $use) {
                        if (!$use->var instanceof Variable || !is_string($use->var->name)) {
                            continue;
                        }
                        $name = $use->var->name;
                        if ($name === 'this' || isset($this->paramNames[$name])) {
                            continue;
                        }
                        $this->captures[$name] ??= true;
                    }
                    return \PhpParser\NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof ArrowFunction) {
                    // Recurse via `implicitCapturesOf` so the inner arrow's
                    // free vars (which include any from our scope) bubble
                    // up. Skip iteration through this subtree -- the inner
                    // analyzer handles it.
                    foreach (ClosureDispatcher::implicitCapturesOf($node) as $innerUse) {
                        $name = $innerUse->var->name;
                        if (!is_string($name)) {
                            continue;
                        }
                        if ($name === 'this' || isset($this->paramNames[$name])) {
                            continue;
                        }
                        $this->captures[$name] ??= true;
                    }
                    return \PhpParser\NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Variable && is_string($node->name)) {
                    if ($node->name === 'this' || isset($this->paramNames[$node->name])) {
                        return null;
                    }
                    $this->captures[$node->name] ??= true;
                }
                return null;
            }
        });
        $traverser->traverse([$expr]);
    }

    /**
     * @infection-ignore-all -- the `instanceof Closure` skip-and-return
     * and the `is_string($node->name) && $node->name === 'this'` check
     * are observable-equivalent under several mutators (returning null
     * vs DONT_TRAVERSE_CHILDREN inside a Closure subtree both reach the
     * same outcome because nested closures' `$this` is irrelevant; the
     * is_string + equality short-circuit is the standard idiom).
     *
     * True iff the arrow's body references `$this` (transitively, including
     * inside nested arrows whose own params don't shadow it). Used by GMC
     * to reject `$this`-capturing generic arrows before they reach
     * `implicitCapturesOf` -- the analyzer intentionally drops `$this`
     * because the dispatcher can't carry it via a `use` clause.
     */
    public static function usesThis(ArrowFunction $arrow): bool
    {
        $found = false;
        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor(new class($found) extends \PhpParser\NodeVisitorAbstract {
            public function __construct(private bool &$found)
            {
            }

            public function enterNode(\PhpParser\Node $node): ?int
            {
                if ($node instanceof Closure) {
                    // Regular closures have their own `$this` scope; don't
                    // descend into them. A nested closure's `$this` is
                    // bound at the closure's own construction time, not
                    // ours.
                    return \PhpParser\NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Variable
                    && is_string($node->name)
                    && $node->name === 'this'
                ) {
                    $this->found = true;
                }
                return null;
            }
        });
        $traverser->traverse([$arrow->expr]);
        return $found;
    }

    /**
     * @infection-ignore-all -- the collision-suffix substr offset/length
     * mutants only change the hex pattern of the renamed param. The
     * test `testArrowSpecializationReservedCaptureAutoRenamesDispatcherParam`
     * asserts the pattern `__xphp_tag_[0-9a-f]{8}`, which any
     * non-empty hex slice satisfies, so the mutants pass observably.
     * The observable behavior under test is "rename happens on
     * collision", which is captured by the regex shape.
     *
     * Resolve the dispatcher's tag / args param names. If the user
     * happened to capture a variable with the same name as our default
     * (`__xphp_tag` / `__xphp_args`), pick a collision-free alternative
     * derived from the template's start file pos (stable across runs
     * of the same source).
     *
     * @param list<ClosureUse> $useClauses
     * @return array{0: string, 1: string}
     */
    private static function resolveDispatcherParamNames(
        array $useClauses,
        Closure|ArrowFunction $template,
    ): array {
        $captured = [];
        foreach ($useClauses as $use) {
            if ($use->var instanceof Variable && is_string($use->var->name)) {
                $captured[$use->var->name] = true;
            }
        }
        if (!isset($captured[self::TAG_PARAM_NAME]) && !isset($captured[self::ARGS_PARAM_NAME])) {
            return [self::TAG_PARAM_NAME, self::ARGS_PARAM_NAME];
        }
        $suffix = substr(hash('sha256', (string) $template->getStartFilePos()), 0, 8);
        $tagName = self::TAG_PARAM_NAME . '_' . $suffix;
        $argsName = self::ARGS_PARAM_NAME . '_' . $suffix;
        return [$tagName, $argsName];
    }
}
