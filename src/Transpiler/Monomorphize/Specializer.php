<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
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
            if ($cloned instanceof \PhpParser\Node\Stmt\Class_) {
                $cloned->implements[] = $marker;
            } elseif ($cloned instanceof \PhpParser\Node\Stmt\Interface_) {
                $cloned->extends[] = $marker;
            }
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($substitution) extends NodeVisitorAbstract {
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
                        return self::typeRefToNode($concrete, $node->getAttributes());
                    }

                    $args = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
                    if (is_array($args) && $args !== []) {
                        $substituted = array_map(
                            fn (TypeRef $a): TypeRef => self::substituteTypeRef($a, $this->substitution),
                            $args,
                        );
                        $node->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, $substituted);
                    }
                }

                return null;
            }

            private static function typeRefToNode(TypeRef $ref, array $attrs): Node
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
             * @param array<string, TypeRef> $subst
             */
            private static function substituteTypeRef(TypeRef $ref, array $subst): TypeRef
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
        });

        $traverser->traverse([$cloned]);

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

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($substitution) extends \PhpParser\NodeVisitorAbstract {
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
                        if ($concrete->isScalar) {
                            return new Identifier($concrete->name, $node->getAttributes());
                        }
                        return new FullyQualified(ltrim($concrete->name, '\\'), $node->getAttributes());
                    }
                }
                return null;
            }
        });
        $traverser->traverse([$cloned]);

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

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($substitution) extends \PhpParser\NodeVisitorAbstract {
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
                        if ($concrete->isScalar) {
                            return new Identifier($concrete->name, $node->getAttributes());
                        }
                        return new FullyQualified(ltrim($concrete->name, '\\'), $node->getAttributes());
                    }
                }
                return null;
            }
        });
        $traverser->traverse([$cloned]);

        return $cloned;
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
