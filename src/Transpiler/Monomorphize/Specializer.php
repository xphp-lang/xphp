<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
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
     *
     * The cloned class's `name` is intentionally NOT set here — SpecializedClassGenerator::emit
     * is the single source of truth for the final shortname (derived from the generated FQCN).
     */
    public function specialize(ClassLike $template, array $substitution): ClassLike
    {
        $originalTemplateFqn = $template->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);

        $cloned = self::deepClone($template);
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

        self::runSubstitutingVisitor($cloned, $substitution);

        return $cloned;
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
                        $substituted = array_map(
                            fn (TypeRef $a): TypeRef => Specializer::substituteTypeRef($a, $this->substitution),
                            $args,
                        );
                        $node->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, $substituted);
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
