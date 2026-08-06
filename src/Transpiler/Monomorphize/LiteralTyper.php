<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

/**
 * The context-free half of {@see ExpressionTyper}: the static type of an argument expression that
 * can be read off the expression itself, with no surrounding scope. It covers scalar literals
 * (`5` → int, `'x'` → string, `1.0` → float, `true`/`false` → bool), array literals (`[...]` →
 * array), and object construction (`new X(...)` → the constructed type, carrying any explicit
 * turbofish arguments the parser resolved onto the `new`).
 *
 * Everything flow-dependent — a `$var`, a `$this->prop`, a call return — is out of scope here and
 * yields null; the monomorphizer's own receiver/scope tracker answers those (and typically composes
 * with this typer, delegating literal/`new` shapes to it). A `new` whose type is not fully concrete
 * (an un-turbofished generic construction, or an anonymous/dynamic class) also yields null: this
 * typer never invents a type it cannot read directly and completely.
 */
final class LiteralTyper implements ExpressionTyper
{
    public function typeOf(Expr $expr): ?TypeRef
    {
        if ($expr instanceof Int_) {
            return new TypeRef('int', isScalar: true);
        }
        if ($expr instanceof Float_) {
            return new TypeRef('float', isScalar: true);
        }
        if ($expr instanceof String_) {
            return new TypeRef('string', isScalar: true);
        }
        if ($expr instanceof ConstFetch) {
            $name = strtolower($expr->name->toString());
            return $name === 'true' || $name === 'false'
                ? new TypeRef('bool', isScalar: true)
                : null;
        }
        if ($expr instanceof Array_) {
            return new TypeRef('array', isScalar: true);
        }
        if ($expr instanceof New_) {
            return self::typeOfNew($expr);
        }
        return null;
    }

    /**
     * The constructed type of a `new X(...)` expression: the class's resolved FQN plus any
     * turbofish type arguments the parser attached (`new Box::<int>()` → `Box<int>`). Null for a
     * dynamic (`new $class()`) or anonymous class, and for a construction that is not fully concrete
     * (`new Box::<T>()` inside a template, or a bare generic `new Box(...)` awaiting its own
     * inference) — an abstract or unresolved type is no basis for inferring another.
     */
    private static function typeOfNew(New_ $new): ?TypeRef
    {
        if (!$new->class instanceof Name) {
            return null;
        }
        $resolved = $new->class->getAttribute(XphpSourceParser::ATTR_RESOLVED_FQN);
        $args = $new->class->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
        /** @var list<TypeRef> $argRefs */
        $argRefs = is_array($args) ? $args : [];
        $ref = new TypeRef(is_string($resolved) ? $resolved : $new->class->toString(), $argRefs);
        return $ref->isConcrete() ? $ref : null;
    }
}
