<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;

/**
 * The five read-only symbol tables the call-site rewriter consults in lockstep, bundled
 * behind typed accessors. Built once in {@see GenericMethodCompiler::process()} after the
 * per-file merge and threaded — as a single value — into the rewrite visitor, replacing
 * five hand-typed array parameters (and the value-type-drop risk each carried).
 *
 * Strictly read during the rewrite: the maps are never mutated once wrapped. The mutable
 * per-run state (`alreadyGenerated`, `topLevelAppends`) is intentionally NOT part of this
 * value — those remain separate by-reference parameters.
 */
final readonly class TemplateIndex
{
    /**
     * @param array<string, ClassMethod> $methodTemplates    keyed by "classFqn::methodName" (generics only)
     * @param array<string, ClassLike> $classesByFqn         every class-like by FQN
     * @param array<string, Function_> $functionTemplates    generic free functions only, by FQN
     * @param array<string, ?Namespace_> $functionNamespaces enclosing Namespace_ per fqn, or null for bare top-level
     * @param array<string, Function_> $allFunctionsByFqn    every free function (generic or not) by FQN
     */
    public function __construct(
        private array $methodTemplates,
        private array $classesByFqn,
        private array $functionTemplates,
        private array $functionNamespaces,
        private array $allFunctionsByFqn,
    ) {
    }

    /** The generic-method template for `$classFqn::$method`, or null when none is indexed. */
    public function methodTemplate(string $classFqn, string $method): ?ClassMethod
    {
        return $this->methodTemplates[(string) new MethodKey($classFqn, $method)] ?? null;
    }

    /** The class-like declaration for `$fqn`, or null when it isn't indexed. */
    public function classLike(string $fqn): ?ClassLike
    {
        return $this->classesByFqn[$fqn] ?? null;
    }

    /** The generic free-function template for `$fqn`, or null when `$fqn` is not a generic function. */
    public function functionTemplate(string $fqn): ?Function_
    {
        return $this->functionTemplates[$fqn] ?? null;
    }

    /** Whether `$fqn` names an indexed generic free-function template. */
    public function hasFunctionTemplate(string $fqn): bool
    {
        return isset($this->functionTemplates[$fqn]);
    }

    /** The enclosing `Namespace_` node of the generic function `$fqn`, or null for a bare top-level one. */
    public function functionNamespaceNode(string $fqn): ?Namespace_
    {
        return $this->functionNamespaces[$fqn] ?? null;
    }

    /** Any free function (generic or not) by FQN, or null when none is indexed under `$fqn`. */
    public function allFunction(string $fqn): ?Function_
    {
        return $this->allFunctionsByFqn[$fqn] ?? null;
    }

    /**
     * Resolve a free-function call target against the caller's function-name scope, with
     * PHP's global fallback: try the name resolved through `$ctx` (`use function`, current
     * namespace), then — for an unqualified, non-fully-qualified name absent there — the
     * global function of that bare name. Returns null when nothing resolves.
     */
    public function resolveFunction(Name $name, NamespaceContext $ctx): ?Function_
    {
        $fn = $this->allFunction($ctx->resolveFunctionName($name));
        if ($fn === null && !$name->isQualified() && !$name->isFullyQualified()) {
            // An unqualified name absent from the current namespace resolves to the
            // global function of that name (PHP's fallback).
            $fn = $this->allFunction($name->toString());
        }
        return $fn;
    }
}
