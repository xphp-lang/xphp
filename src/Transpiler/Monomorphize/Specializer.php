<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Modifiers;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Clones a generic class template and substitutes every reference to its type
 * parameters (T, K, V, ...) with the concrete TypeRefs from the instantiation.
 *
 * Substitution happens at three positions inside the cloned AST:
 *  1. Bare Name nodes that are single-segment and match a type-param key
 *     (e.g. `public T $item` → `public \App\Models\Plastic $item` or `public string $item`).
 *  2. Generic-args TypeRef trees attached to Name nodes (e.g. `public Box<T> $b`):
 *     walk the trees, substitute any type-param leaves, leave the Name node in place
 *     with concrete (possibly-still-nested) args. Downstream RegistryCollector + CallSiteRewriter
 *     pick these up.
 *  3. `new T(...)` expressions inside method bodies are covered automatically because the
 *     class field of New_ is itself a Name that the same logic handles.
 *
 * The three public entry points (`specialize`, `specializeMethod`, `specializeFunction`)
 * share a single substitution visitor — see `buildSubstitutingVisitor`. Keeping the
 * substitution logic in one place is what lets `function wrap<T>(T $x): Box<T> { ... }`
 * specialize correctly: the visitor recursively descends into ATTR_GENERIC_ARGS so the
 * inner `Box<T>` arg list becomes `Box<int>` after substitution.
 */
final class Specializer
{
    /**
     * @param array<string, TypeRef> $substitution Type-param name → concrete TypeRef.
     * @param list<TypeParam> $typeParams The template's type-params, used to
     *   variance-erase constructor parameters typed by a covariant/contravariant
     *   `T`. Empty (the default) disables erasure — callers that don't have the
     *   params, e.g. unit tests, get the plain substitution.
     *
     * The cloned class's `name` is intentionally NOT set here — SpecializedClassGenerator::emit
     * is the single source of truth for the final shortname (derived from the generated FQCN).
     */
    public function specialize(ClassLike $template, array $substitution, array $typeParams = []): ClassLike
    {
        $originalTemplateFqn = $template->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);

        // Record which constructor parameters must be variance-erased BEFORE the
        // substituting visitor rewrites their `T` type to the concrete type.
        $ctorErasures = self::variantConstructorErasures($template, $typeParams);

        $cloned = self::deepClone($template);
        assert($cloned instanceof ClassLike);
        $cloned->setAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS, null);
        $cloned->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, null);

        // Wire the specialized class/interface to the template's marker so user code can
        // do `$x instanceof App\Containers\Box` and get true for ANY Box<...>. Classes
        // implement, interfaces extend; traits don't get a marker (PHP can't instanceof
        // a trait) — see CallSiteRewriter for the matching marker emission.
        if (is_string($originalTemplateFqn)) {
            $marker = new FullyQualified(ltrim($originalTemplateFqn, '\\'));
            if ($cloned instanceof Class_) {
                $cloned->implements[] = $marker;
            } elseif ($cloned instanceof Interface_) {
                $cloned->extends[] = $marker;
            }
        }

        // A variant class's specializations participate in `extends` subtype
        // edges (VarianceEdgeEmitter); a `final` parent in that chain would
        // PHP-fatal at autoload. These generated classes are internal — user
        // code references the marker interface or the turbofish call site, never
        // these names — so dropping `final` here is invisible and safe.
        if ($cloned instanceof Class_ && self::hasVariantParam($typeParams)) {
            $cloned->flags &= ~Modifiers::FINAL;
        }

        self::runSubstitutingVisitor($cloned, $substitution);

        // Re-type the recorded constructor params to their erased (bound / `mixed`)
        // form so every specialization's `__construct` signature is identical and
        // stays LSP-compatible across the variance `extends` edge (a concrete
        // `T`-typed ctor would PHP-fatal at autoload).
        self::applyConstructorErasures($cloned, $ctorErasures);

        return $cloned;
    }

    /**
     * For a variant template, find the NON-promoted `__construct` parameters typed
     * by a covariant/contravariant type-param and compute the type to emit instead
     * of the concrete one: the param's bound when it's a single non-generic leaf,
     * else `mixed`. Promoted params are skipped — they are properties and stay
     * strictly invariant (rejected upstream by the variance validator).
     *
     * @param list<TypeParam> $typeParams
     * @return array<int, TypeRef> constructor-parameter index → erased TypeRef
     */
    private static function variantConstructorErasures(ClassLike $template, array $typeParams): array
    {
        $erasedByName = [];
        foreach ($typeParams as $typeParam) {
            if ($typeParam->variance === Variance::Invariant) {
                continue;
            }
            $erasedByName[$typeParam->name] =
                ($typeParam->bound instanceof BoundLeaf && !$typeParam->bound->type->isGeneric())
                    ? $typeParam->bound->type
                    : new TypeRef('mixed', [], true, false);
        }
        if ($erasedByName === []) {
            return [];
        }

        $ctor = self::findConstructor($template);
        if ($ctor === null) {
            return [];
        }

        $erasures = [];
        foreach ($ctor->params as $i => $param) {
            if ($param->flags !== 0) {
                continue; // promoted params are properties — left invariant/rejected.
            }
            $type = $param->type;
            if ($type instanceof Name && count($type->getParts()) === 1) {
                $name = $type->getParts()[0];
                if (isset($erasedByName[$name])) {
                    $erasures[$i] = $erasedByName[$name];
                }
            }
        }
        return $erasures;
    }

    /**
     * @param array<int, TypeRef> $erasures constructor-parameter index → erased TypeRef
     */
    private static function applyConstructorErasures(ClassLike $cloned, array $erasures): void
    {
        if ($erasures === []) {
            return;
        }
        $ctor = self::findConstructor($cloned);
        if ($ctor === null) {
            return;
        }
        foreach ($erasures as $i => $erasedRef) {
            $param = $ctor->params[$i] ?? null;
            if ($param instanceof Param) {
                // The erased type is a fresh synthetic node (`mixed` or a bound), so
                // no source-position attributes are carried over. typeRefToNode returns
                // an Identifier or a (Fully)Qualified Name — both valid for Param::$type.
                $erased = self::typeRefToNode($erasedRef, []);
                assert($erased instanceof Identifier || $erased instanceof Name);
                $param->type = $erased;
            }
        }
    }

    /** @param list<TypeParam> $typeParams */
    private static function hasVariantParam(array $typeParams): bool
    {
        foreach ($typeParams as $typeParam) {
            if ($typeParam->variance !== Variance::Invariant) {
                return true;
            }
        }
        return false;
    }

    private static function findConstructor(ClassLike $node): ?ClassMethod
    {
        foreach ($node->getMethods() as $method) {
            if ($method->name->toLowerString() === '__construct') {
                return $method;
            }
        }
        return null;
    }

    /**
     * Specialize a single generic method: clone the ClassMethod, substitute every
     * Name reference to a type-param with the matching concrete TypeRef, drop the
     * method-level genericParams attribute, and rename to the supplied mangled form.
     *
     * The mangled name is supplied by the caller (GenericMethodCompiler) so the
     * hashing scheme stays in one place — Specializer is dumb about the naming
     * convention.
     *
     * @param array<string, TypeRef> $substitution
     */
    public function specializeMethod(ClassMethod $template, array $substitution, string $mangledName): ClassMethod
    {
        /** @var ClassMethod $cloned */
        $cloned = self::deepClone($template);
        $cloned->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS, null);
        $cloned->name = new Identifier($mangledName, $cloned->name->getAttributes());

        self::runSubstitutingVisitor($cloned, $substitution);

        return $cloned;
    }

    /**
     * Specialize a free generic function. Same substitution shape as specializeMethod;
     * the only difference is the AST node kind (Function_ vs ClassMethod).
     *
     * @param array<string, TypeRef> $substitution
     */
    public function specializeFunction(Function_ $template, array $substitution, string $mangledName): Function_
    {
        /** @var Function_ $cloned */
        $cloned = self::deepClone($template);
        $cloned->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS, null);
        $cloned->name = new Identifier($mangledName, $cloned->name->getAttributes());

        self::runSubstitutingVisitor($cloned, $substitution);

        return $cloned;
    }

    /**
     * Run the shared substitution visitor over a cloned template. Mutates `$cloned` in place.
     *
     * @param array<string, TypeRef> $substitution
     */
    private static function runSubstitutingVisitor(Node $cloned, array $substitution): void
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(self::buildSubstitutingVisitor($substitution));
        $traverser->traverse([$cloned]);
    }

    /**
     * Build the AST visitor that performs both type-param substitution flavors:
     *  - Bare single-segment `Name` matching a type-param key → replace with the
     *    concrete type as a Node (Identifier for scalars, FullyQualified for class names,
     *    Name carrying ATTR_GENERIC_ARGS for nested generics).
     *  - Any Name carrying ATTR_GENERIC_ARGS → walk the TypeRef tree and substitute
     *    type-param leaves with their concrete TypeRefs. This is what makes
     *    `Box<T>` inside `class Wrapper<T> { ... }` (or `function wrap<T>(...): Box<T>`)
     *    end up as `Box<int>` after specialization, ready for the call-site rewriter.
     *
     * @param array<string, TypeRef> $substitution
     */
    private static function buildSubstitutingVisitor(array $substitution): NodeVisitorAbstract
    {
        return new class($substitution) extends NodeVisitorAbstract {
            /** @param array<string, TypeRef> $substitution */
            public function __construct(private array $substitution)
            {
            }

            public function leaveNode(Node $node): ?Node
            {
                if ($node instanceof Name && !$node->isFullyQualified()) {
                    $parts = $node->getParts();
                    if (count($parts) === 1 && isset($this->substitution[$parts[0]])) {
                        $concrete = $this->substitution[$parts[0]];
                        return Specializer::typeRefToNode($concrete, $node->getAttributes());
                    }

                    $args = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
                    if (is_array($args) && $args !== []) {
                        /** @var list<TypeRef> $args — ATTR_GENERIC_ARGS is a TypeRef list (set by XphpSourceParser); the type-hint lets array_map infer the callback's parameter as TypeRef. */
                        $substituted = array_map(
                            fn (TypeRef $a): TypeRef => Specializer::substituteTypeRef($a, $this->substitution),
                            $args,
                        );
                        $node->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, $substituted);

                        return null;
                    }

                    // Bare, non-generic class/interface name carried over from the
                    // template (extends Countable, new ArrayIterator, ...). The parser
                    // tagged it with the FQN resolved against the source file's
                    // namespace + use map; fully-qualify it now so it survives
                    // relocation into the XPHP\Generated\... namespace. Only runs on
                    // the cloned specialized AST -- user files never reach here.
                    $resolvedFqn = $node->getAttribute(XphpSourceParser::ATTR_RESOLVED_FQN);
                    if (is_string($resolvedFqn)) {
                        return new FullyQualified($resolvedFqn, $node->getAttributes());
                    }
                }

                return null;
            }
        };
    }

    /**
     * Convert a concrete TypeRef into the AST node form a type-hint slot accepts:
     *  - scalars → `Identifier('int')` etc.
     *  - non-generic class → `FullyQualified('App\\Models\\Plastic')`.
     *  - generic class → `Name` carrying ATTR_GENERIC_ARGS so the downstream
     *    call-site rewriter can rewrite it to the specialized FQCN.
     *
     * Public to keep the shared visitor (which lives in an anonymous class) able to
     * call into it without leaking visibility through reflection tricks.
     *
     * @param array<string, mixed> $attrs
     */
    public static function typeRefToNode(TypeRef $ref, array $attrs): Node
    {
        if ($ref->isScalar) {
            return new Identifier($ref->name, $attrs);
        }
        if (!$ref->isGeneric()) {
            return new FullyQualified(ltrim($ref->name, '\\'), $attrs);
        }
        $name = new Name(ltrim($ref->name, '\\'), $attrs);
        $name->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, $ref->args);
        $name->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, ltrim($ref->name, '\\'));
        return $name;
    }

    /**
     * Recursively substitute type-param leaves inside a TypeRef tree.
     *
     * Public for the same reason as `typeRefToNode` — the anonymous-class visitor
     * calls back into Specializer to avoid duplicating the logic.
     *
     * @param array<string, TypeRef> $subst
     */
    public static function substituteTypeRef(TypeRef $ref, array $subst): TypeRef
    {
        if ($ref->isTypeParam && isset($subst[$ref->name])) {
            return $subst[$ref->name];
        }
        if ($ref->args === []) {
            return $ref;
        }
        $newArgs = array_map(
            static fn (TypeRef $a): TypeRef => self::substituteTypeRef($a, $subst),
            $ref->args,
        );
        return new TypeRef($ref->name, $newArgs, $ref->isScalar, $ref->isTypeParam);
    }

    /**
     * Deep-clone an AST subtree. nikic/php-parser's __clone is shallow; we need
     * a recursive clone so mutations to the specialized copy don't bleed back
     * into the template (which may be specialized again with different types).
     */
    private static function deepClone(Node $node): Node
    {
        $cloned = clone $node;

        foreach ($node->getSubNodeNames() as $name) {
            $value = $cloned->$name;
            $cloned->$name = self::cloneValue($value);
        }

        return $cloned;
    }

    private static function cloneValue(mixed $value): mixed
    {
        if ($value instanceof Node) {
            return self::deepClone($value);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::cloneValue($v);
            }
            return $out;
        }

        return $value;
    }
}
