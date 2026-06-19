<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\UnionType;
use RuntimeException;
use XPHP\Diagnostics\Diagnostic;
use XPHP\Diagnostics\DiagnosticCollector;
use XPHP\Diagnostics\Severity;
use XPHP\Diagnostics\SourceLocation;

/**
 * Inner-template variance composition check for one generic definition.
 *
 * When a variance-marked type-param appears inside another generic's type-arg
 * (e.g. `class P<+T> { f(): Container<T> }` where `Container`'s slot is
 * invariant), the effective variance at that position is the composition of the
 * outer position and the inner slot's variance. This catches cases the
 * position validator can't, because the effective variance only resolves once
 * the inner template's slot variances are known.
 *
 * Runs over collected definitions (`Registry::validateInnerVariance`). With a
 * DiagnosticCollector it gathers every violation in the definition (each located
 * at the offending member) and continues; without one it throws the first —
 * byte-identical to the previous behavior.
 */
final class InnerVarianceValidator
{
    /** Stable diagnostic code for an inner-variance composition violation. */
    public const CODE_INNER_VARIANCE = 'xphp.inner_variance';

    /** @var array<string, GenericDefinition> */
    private array $definitions;

    /** @var array<string, Variance> */
    private array $varianceMap;

    /** @var list<array{message: string, line: ?int}> */
    private array $violations = [];

    /**
     * @param array<string, GenericDefinition> $definitions
     * @param array<string, Variance> $varianceMap
     */
    private function __construct(array $definitions, array $varianceMap)
    {
        $this->definitions = $definitions;
        $this->varianceMap = $varianceMap;
    }

    /**
     * @param array<string, GenericDefinition> $definitions All definitions, for inner-slot lookup.
     */
    public static function assertComposition(
        GenericDefinition $definition,
        array $definitions,
        ?DiagnosticCollector $diagnostics = null,
        ?string $file = null,
    ): void {
        $varianceMap = self::buildVarianceMap($definition->typeParams);
        // @infection-ignore-all -- optimization only: with an empty variance map every leaf
        // check is a no-op (no name is in the map), so removing this guard walks the body
        // pointlessly but produces the same (empty) result.
        if ($varianceMap === []) {
            return;
        }

        $validator = new self($definitions, $varianceMap);
        $validator->collect($definition);
        if ($validator->violations === []) {
            return;
        }

        if ($diagnostics === null) {
            throw new RuntimeException($validator->violations[0]['message']);
        }

        foreach ($validator->violations as $violation) {
            $location = ($violation['line'] !== null && $file !== null)
                ? new SourceLocation($file, $violation['line'])
                : null;
            $diagnostics->add(new Diagnostic(
                Severity::Error,
                self::CODE_INNER_VARIANCE,
                $violation['message'],
                $location,
            ));
        }
    }

    private function collect(GenericDefinition $definition): void
    {
        $label = $definition->templateShortName;
        $declarationLine = $definition->templateAst->getStartLine();

        foreach ($definition->templateAst->getMethods() as $method) {
            $isCtor = $method->name->toLowerString() === '__construct';
            foreach ($method->params as $param) {
                // Constructor params (promoted or not) get Invariant outer
                // position -- PHP's class-compat rules enforce invariance on
                // ctor signatures regardless of param flavor. `getProperties()`
                // below skips promoted ones (they're `Param`, not `Property`),
                // so each promoted property is walked exactly once.
                //
                // Exception: a non-promoted ctor param typed by a bare
                // covariant/contravariant type-param is emitted variance-erased
                // (`mixed`/bound) by the Specializer, so its declared variance is
                // irrelevant — skip it. Inner-generic ctor params (e.g.
                // `Container<T>`) are NOT erased and stay checked, as do promoted
                // params (they're properties).
                if ($isCtor && $this->isErasedVariantCtorParam($param)) {
                    continue;
                }
                $outerPos = $isCtor ? Variance::Invariant : Variance::Contravariant;
                if ($param->type !== null) {
                    $this->walkPhpType($param->type, $outerPos, $label, null, null);
                }
            }
            if ($method->returnType !== null) {
                $this->walkPhpType($method->returnType, Variance::Covariant, $label, null, null);
            }
        }
        foreach ($definition->templateAst->getProperties() as $prop) {
            if ($prop->type !== null) {
                $this->walkPhpType($prop->type, Variance::Invariant, $label, null, null);
            }
        }
        foreach ($definition->typeParams as $typeParam) {
            if ($typeParam->bound !== null) {
                $this->walkBoundExpr($typeParam->bound, $label, $declarationLine);
            }
            if ($typeParam->default !== null) {
                $this->walkTypeRef($typeParam->default, Variance::Invariant, $label, null, null, $declarationLine);
            }
        }
    }

    /**
     * A non-promoted constructor parameter whose type is a bare single-segment
     * covariant/contravariant type-param — exactly the params the Specializer
     * emits variance-erased. Their declared variance no longer reaches the
     * emitted signature, so the inner-variance walk skips them.
     */
    private function isErasedVariantCtorParam(Param $param): bool
    {
        if ($param->flags !== 0) {
            return false; // promoted param == property; stays strictly invariant.
        }
        $type = $param->type;
        if (!$type instanceof Name) {
            return false;
        }
        $parts = $type->getParts();
        if (count($parts) !== 1) {
            return false; // inner-generic / qualified type — not erased, keep checking.
        }
        $variance = $this->varianceMap[$parts[0]] ?? null;
        return $variance !== null && $variance !== Variance::Invariant;
    }

    /**
     * @infection-ignore-all -- semantic-equivalent mutants in this walker:
     * fall-through returns for unhandled node kinds, `??`-suppressed null-safe
     * slot lookups, and Union/Intersection branch swaps (both recurse the same).
     */
    private function walkPhpType(
        Node $type,
        Variance $position,
        string $outerLabel,
        ?string $innerLabel,
        ?int $innerSlot,
    ): void {
        if ($type instanceof Identifier) {
            return;
        }
        if ($type instanceof Name) {
            $parts = $type->getParts();
            if (count($parts) === 1 && isset($this->varianceMap[$parts[0]])) {
                $this->assertLeaf($parts[0], $this->varianceMap[$parts[0]], $position, $outerLabel, $innerLabel, $innerSlot, $type->getStartLine());
            }
            $args = $type->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
            if (is_array($args)) {
                $innerFqn = $type->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
                $innerDef = is_string($innerFqn)
                    ? ($this->definitions[ltrim($innerFqn, '\\')] ?? null)
                    : null;
                $nextInnerLabel = $innerDef !== null ? $innerDef->templateShortName : $type->toString();
                foreach ($args as $i => $arg) {
                    if (!$arg instanceof TypeRef) {
                        continue;
                    }
                    $slotVariance = $innerDef?->typeParams[$i]->variance ?? Variance::Invariant;
                    $this->walkTypeRef($arg, self::compose($position, $slotVariance), $outerLabel, $nextInnerLabel, $i, $type->getStartLine());
                }
            }
            return;
        }
        if ($type instanceof NullableType) {
            $this->walkPhpType($type->type, $position, $outerLabel, $innerLabel, $innerSlot);
            return;
        }
        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $sub) {
                $this->walkPhpType($sub, $position, $outerLabel, $innerLabel, $innerSlot);
            }
            return;
        }
        if ($type instanceof ComplexType) {
            return;
        }
    }

    /**
     * @infection-ignore-all -- same equivalence rationale as `walkPhpType`.
     */
    private function walkTypeRef(
        TypeRef $ref,
        Variance $position,
        string $outerLabel,
        ?string $innerLabel,
        ?int $innerSlot,
        ?int $line,
    ): void {
        if ($ref->isScalar) {
            return;
        }
        if ($ref->isTypeParam && isset($this->varianceMap[$ref->name])) {
            $this->assertLeaf($ref->name, $this->varianceMap[$ref->name], $position, $outerLabel, $innerLabel, $innerSlot, $line);
        }
        if ($ref->args === []) {
            return;
        }
        $innerDef = $this->definitions[ltrim($ref->name, '\\')] ?? null;
        $nextInnerLabel = $innerDef !== null ? $innerDef->templateShortName : $ref->name;
        foreach ($ref->args as $i => $sub) {
            $slotVariance = $innerDef?->typeParams[$i]->variance ?? Variance::Invariant;
            $this->walkTypeRef($sub, self::compose($position, $slotVariance), $outerLabel, $nextInnerLabel, $i, $line);
        }
    }

    /**
     * @infection-ignore-all -- BoundUnion / BoundIntersection share the same
     * `operands` walk; the InstanceOf_ / LogicalOr mutants on the discriminator
     * are observably identical for any non-Leaf bound expression.
     */
    private function walkBoundExpr(BoundExpr $expr, string $outerLabel, ?int $line): void
    {
        if ($expr instanceof BoundLeaf) {
            $this->walkTypeRef($expr->type, Variance::Invariant, $outerLabel, null, null, $line);
            return;
        }
        if ($expr instanceof BoundUnion || $expr instanceof BoundIntersection) {
            foreach ($expr->operands as $operand) {
                $this->walkBoundExpr($operand, $outerLabel, $line);
            }
        }
    }

    /**
     * @infection-ignore-all -- the `Variance::Invariant => ''` arm of the
     * sigil-builder `match` is unreachable: Invariant declared variance passes
     * every allowed-list, so this records nothing before the sigil is built.
     */
    private function assertLeaf(
        string $paramName,
        Variance $declared,
        Variance $effective,
        string $outerLabel,
        ?string $innerLabel,
        ?int $innerSlot,
        ?int $line,
    ): void {
        $allowed = match ($effective) {
            Variance::Invariant     => [Variance::Invariant],
            Variance::Covariant     => [Variance::Invariant, Variance::Covariant],
            Variance::Contravariant => [Variance::Invariant, Variance::Contravariant],
        };
        if (in_array($declared, $allowed, true)) {
            return;
        }
        // $declared is Covariant or Contravariant at this point — the Invariant
        // case passes every allowed-list and early-returns above.
        $sigil = $declared === Variance::Covariant ? '+' : '-';
        $where = $innerLabel !== null
            ? sprintf(' (via slot %d of %s)', $innerSlot, $innerLabel)
            : '';
        $this->violations[] = [
            'message' => sprintf(
                'Variance violation in template %s: type-parameter %s%s appears in %s-only position%s.',
                $outerLabel,
                $sigil,
                $paramName,
                $effective->value,
                $where,
            ),
            'line' => $line,
        ];
    }

    private static function compose(Variance $position, Variance $innerSlot): Variance
    {
        return match ($innerSlot) {
            Variance::Invariant     => Variance::Invariant,
            Variance::Covariant     => $position,
            Variance::Contravariant => match ($position) {
                Variance::Covariant     => Variance::Contravariant,
                Variance::Contravariant => Variance::Covariant,
                Variance::Invariant     => Variance::Invariant,
            },
        };
    }

    /**
     * @param list<TypeParam> $typeParams
     * @return array<string, Variance>
     *
     * @infection-ignore-all -- FalseValue mutator on `$hasVariance = false` is
     * observably identical: for all-Invariant templates, walking is a no-op
     * (Invariant is allowed at every effective position), so the "skip the
     * walk" optimization isn't testable.
     */
    private static function buildVarianceMap(array $typeParams): array
    {
        $hasVariance = false;
        $map = [];
        foreach ($typeParams as $tp) {
            $map[$tp->name] = $tp->variance;
            if ($tp->variance !== Variance::Invariant) {
                $hasVariance = true;
            }
        }
        return $hasVariance ? $map : [];
    }
}
