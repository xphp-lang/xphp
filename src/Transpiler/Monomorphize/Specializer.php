<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Variable;
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
     * Type parameters in every position — including constructor parameters — are
     * substituted to their *concrete* type; nothing is erased. PHP exempts
     * `__construct` from LSP signature checks, so a `T`-typed constructor parameter
     * specializes to its real type (`Banana ...$items`) and stays valid across the
     * variance `extends` chain, giving a real runtime type check at construction.
     * A `T`-typed *public/protected property* (mutable, readonly, or promoted) is the
     * one shape that can't cross the edge — PHP enforces invariant property types across
     * the chain for visible members — and is rejected upstream by the variance-position
     * validator, not erased here. A `T`-typed *private* property DOES cross the edge and
     * is substituted to its real type (PHP doesn't type-check private slots across the
     * chain; each specialization re-emits its own field + accessor). A `final` variant
     * class is likewise rejected upstream (a `final` class can't anchor a variance
     * `extends` edge), so no `final` needs stripping here.
     *
     * The cloned class's `name` is intentionally NOT set here — SpecializedClassGenerator::emit
     * is the single source of truth for the final shortname (derived from the generated FQCN).
     */
    public function specialize(ClassLike $template, array $substitution, int $hashLength = Registry::DEFAULT_HASH_HEX_LENGTH): ClassLike
    {
        $originalTemplateFqn = $template->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);

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

        // Lower an erasable `<U : E>` method (kept on the template by the method compiler) into a
        // concrete, E-mangled member: `contains<U : E>(U)` on Box<Fruit> becomes `contains_T_<hash>`
        // taking `Fruit`. Done before the class-wide substitution; the lowered members are already
        // concrete, so the substitution leaves them untouched.
        $cloned->stmts = $this->lowerErasableMethods($cloned->stmts, $substitution, $hashLength);

        self::runSubstitutingVisitor($cloned, $substitution);

        return $cloned;
    }

    /**
     * Replace each erasable generic method with its E-erased concrete form; non-erasable methods and
     * non-method statements pass through unchanged.
     *
     * @param array<\PhpParser\Node\Stmt> $stmts
     * @param array<string, TypeRef> $classConcrete class-parameter name → concrete TypeRef
     * @return list<\PhpParser\Node\Stmt>
     */
    private function lowerErasableMethods(array $stmts, array $classConcrete, int $hashLength): array
    {
        $classParamNames = array_keys($classConcrete);

        // First pass: every erasable method's E-mangled name. Used to rewrite `$this->m::<...>()`
        // self-calls between erasable methods to the same names the call sites produce.
        $erasedNames = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof ClassMethod) {
                $methodParams = $stmt->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                if (is_array($methodParams)) {
                    /** @var list<TypeParam> $methodParams */
                    if (EnclosingBoundErasure::isErasable($stmt, $methodParams, $classParamNames)) {
                        $erasedNames[$stmt->name->toString()] = Registry::mangledMethodName(
                            $stmt->name->toString(),
                            EnclosingBoundErasure::mangleArgs($methodParams, $classConcrete),
                            $hashLength,
                        );
                    }
                }
            }
        }

        // Second pass: erase each erasable method into a concrete member.
        $out = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof ClassMethod && isset($erasedNames[$stmt->name->toString()])) {
                /** @var list<TypeParam> $methodParams */
                $methodParams = $stmt->getAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS);
                $out[] = $this->eraseMethod($stmt, $methodParams, $classConcrete, $erasedNames[$stmt->name->toString()], $erasedNames);
                continue;
            }
            $out[] = $stmt;
        }
        return $out;
    }

    /**
     * Erase one method: substitute each enclosing-bounded type parameter (and any class parameter) to
     * its concrete bound, rename to the E-mangled name, drop method-genericness, and rewrite any
     * `$this->m::<...>()` self-call to a fellow erasable method to that method's E-mangled name.
     *
     * @param list<TypeParam> $methodParams
     * @param array<string, TypeRef> $classConcrete
     * @param array<string, string> $erasedNames  erasable method name → its E-mangled name
     */
    private function eraseMethod(ClassMethod $method, array $methodParams, array $classConcrete, string $mangled, array $erasedNames): ClassMethod
    {
        $subst = $classConcrete;
        foreach ($methodParams as $param) {
            // @infection-ignore-all — invariantly true: isErasable guarantees every method parameter
            // is a single-leaf enclosing-class bound, so `$param->bound` IS a BoundLeaf and its
            // referent IS a key of $classConcrete. The guard is defensive against a non-erasable call.
            if ($param->bound instanceof BoundLeaf && isset($classConcrete[$param->bound->type->name])) {
                $subst[$param->name] = $classConcrete[$param->bound->type->name];
            }
        }

        $lowered = $this->specializeMethod($method, $subst, $mangled);
        self::rewriteErasableSelfCalls($lowered, $erasedNames);

        return $lowered;
    }

    /**
     * Rewrite `$this->m::<...>()` (and the nullsafe form) where `m` is a fellow erasable method to its
     * E-mangled name, stripping the now-meaningless turbofish. The forwarded type argument is erased,
     * so the call keys on the enclosing class's `E` exactly like every other call to `m`.
     *
     * @param array<string, string> $erasedNames
     */
    private static function rewriteErasableSelfCalls(ClassMethod $method, array $erasedNames): void
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($erasedNames) extends NodeVisitorAbstract {
            /** @param array<string, string> $erasedNames */
            public function __construct(private array $erasedNames)
            {
            }

            public function leaveNode(Node $node): ?Node
            {
                if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall) {
                    return null;
                }
                // @infection-ignore-all — defensive receiver-shape guard: only a literal `$this->m()`
                // with an Identifier name is rewritten. The alternate (`&&`) forms would attempt to
                // process non-`$this` / dynamic-name calls, which an erasable method body never pairs
                // with an erasable target — the `erasedNames` lookup below would miss them regardless.
                if (!$node->var instanceof Variable
                    || $node->var->name !== 'this'
                    || !$node->name instanceof Identifier
                ) {
                    return null;
                }
                $mangled = $this->erasedNames[$node->name->toString()] ?? null;
                if ($mangled === null) {
                    return null;
                }
                $node->name = new Identifier($mangled, $node->name->getAttributes());
                // @infection-ignore-all — clearing the now-stale turbofish attribute is hygiene only:
                // the pretty-printer never emits ATTR_METHOD_GENERIC_ARGS, and no pass reads it on an
                // already-specialized class, so its removal is unobservable in the output.
                $node->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS, null);
                return $node;
            }
        });
        $traverser->traverse([$method]);
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
                // Ground a closure-signature target in place. The erased `\Closure`
                // head that carries it is fully-qualified, so it never reaches the
                // type-param swap below; substitute its type-parameter leaves here.
                if ($node instanceof Name) {
                    $sig = $node->getAttribute(XphpSourceParser::ATTR_CLOSURE_SIG);
                    if ($sig instanceof ClosureSignature) {
                        $node->setAttribute(
                            XphpSourceParser::ATTR_CLOSURE_SIG,
                            Specializer::substituteClosureSignature($sig, $this->substitution),
                        );
                    }
                }

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
     * Ground a closure-signature target ({@see XphpSourceParser::ATTR_CLOSURE_SIG})
     * by substituting its type-parameter leaves with their concrete types, so the
     * conformance validator's post-specialization pass checks `Closure(int): int`
     * (not `Closure(T): T`) against the likewise-substituted returned literal.
     * Mirrors the parser's own signature-resolution walk.
     *
     * Public for the same reason as {@see substituteTypeRef} — the shared
     * anonymous-class visitor calls back into Specializer.
     *
     * @param array<string, TypeRef> $subst
     */
    public static function substituteClosureSignature(ClosureSignature $sig, array $subst): ClosureSignature
    {
        $params = array_map(
            static fn (ClosureSignatureParam $p): ClosureSignatureParam => new ClosureSignatureParam(
                self::substituteSigType($p->type, $subst),
                $p->byRef,
                $p->variadic,
                $p->optional,
            ),
            $sig->params,
        );
        $return = $sig->return === null ? null : self::substituteSigType($sig->return, $subst);

        return new ClosureSignature($params, $return, $sig->nullable);
    }

    /**
     * @param array<string, TypeRef> $subst
     */
    private static function substituteSigType(SigType $type, array $subst): SigType
    {
        if ($type instanceof SigTypeRef) {
            return new SigTypeRef(self::substituteTypeRef($type->type, $subst));
        }
        if ($type instanceof SigClosure) {
            return new SigClosure(self::substituteClosureSignature($type->signature, $subst));
        }
        if ($type instanceof SigUnion) {
            return new SigUnion(array_map(
                static fn (SigType $m): SigType => self::substituteSigType($m, $subst),
                $type->members,
            ));
        }
        if ($type instanceof SigIntersection) {
            return new SigIntersection(array_map(
                static fn (SigType $m): SigType => self::substituteSigType($m, $subst),
                $type->members,
            ));
        }

        // SigRaw (an unstructured DNF / scalar-bearing intersection) is gradual;
        // there is nothing to ground.
        return $type;
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
