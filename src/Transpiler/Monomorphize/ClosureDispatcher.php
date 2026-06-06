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
        $declarations = [];
        $arms = [];
        $seenTags = [];
        foreach ($argSets as $args) {
            $tag = self::tagFor($args, $hashLength);
            if (isset($seenTags[$tag])) {
                continue;
            }
            $seenTags[$tag] = true;
            $spec = $this->buildSpecialization($template, $args, $typeParams, $varName, $namespace, $hashLength);
            $declarations[] = $spec['function'];
            $arms[] = ['tag' => $tag, 'mangledFqn' => $spec['mangledFqn']];
        }
        $dispatcher = $this->buildDispatcherClosure($template, $arms, $useClauses);
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
     * @param list<TypeRef>   $args
     * @param list<TypeParam> $params
     * @return array{function: Function_, tag: string, mangledFqn: string}
     */
    private function buildSpecialization(
        Closure|ArrowFunction $template,
        array $args,
        array $params,
        string $varName,
        string $namespace,
        int $hashLength,
    ): array {
        $shortName = 'closure_' . $varName;
        $mangled = $shortName . '_T_' . Registry::canonicalHash($args, $hashLength);
        $tag = self::tagFor($args, $hashLength);
        $mangledFqn = $namespace !== '' ? $namespace . '\\' . $mangled : $mangled;

        $synthetic = self::syntheticFunctionFromTemplate($template, $shortName, $params);

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
    ): Closure {
        $tagVar = new Variable(self::TAG_PARAM_NAME);
        $argsVar = new Variable(self::ARGS_PARAM_NAME);

        $matchArms = [];
        foreach ($arms as $arm) {
            $matchArms[] = new MatchArm(
                [new String_($arm['tag'])],
                new FuncCall(
                    new FullyQualified($arm['mangledFqn']),
                    [new Arg($argsVar, unpack: true)],
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
}
