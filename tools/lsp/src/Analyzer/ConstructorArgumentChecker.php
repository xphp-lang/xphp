<?php

declare(strict_types=1);

namespace XPHP\Lsp\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\TypeHierarchy;
use XPHP\Transpiler\Monomorphize\TypeRef;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * V1 of the post-monomorphization constructor argument-type checker
 * (cycle K-ctor-arg-check).  Walks every `new Foo(…)` and `new Foo<T>(…)`
 * expression in the workspace and emits an `xphp.ctor-arg-mismatch`
 * diagnostic when a supplied argument's statically-known type doesn't
 * satisfy the constructor parameter's declared type (after type-arg
 * substitution for the generic case).
 *
 * Argument type inference is intentionally narrow -- only the cases
 * where the AST alone tells us the type:
 *   - `new ClassName(...)` → ClassName FQN
 *   - string / int / float literals → the obvious scalar
 *   - `true` / `false` / `null` const fetch → bool / null
 *   - array literal `[…]` → array
 *
 * Variables, method calls, function calls, ternaries, and any other
 * expression whose static type would need flow typing are SKIPPED.
 * That avoids false positives while still catching the prod case
 * (`new StringableBox<Tag>(new User('hello'))` → `User` vs `Tag`).
 *
 * Comparison rules:
 *   - exact match: param type === arg type → OK.
 *   - class hierarchy: TypeHierarchy::isSubtype($actual, $expected)
 *     === true → OK.  null (unknown) → OK (don't false-positive on
 *     types missing from the workspace index).
 *   - nullable param `?T`: `null` literal is always OK; non-null args
 *     are checked against T.
 *   - union `T|U`: OK if actual matches ANY arm.
 *   - intersection `T&U`: OK if actual matches ALL arms.
 *   - mixed / object / callable / iterable / void / never: always
 *     considered satisfied (object accepts any class, mixed accepts
 *     anything, etc.).  No false positives.
 */
final readonly class ConstructorArgumentChecker
{
    /** Scalar param types the checker can compare against literals. */
    private const SCALARS = ['string' => true, 'int' => true, 'float' => true, 'bool' => true, 'array' => true];

    /** Pseudo / supertype params that accept anything. */
    private const PERMISSIVE_TYPES = [
        'mixed' => true,
        'object' => true,
        'callable' => true,
        'iterable' => true,
        'void' => true,
        'never' => true,
        'self' => true,
        'static' => true,
        'parent' => true,
    ];

    /**
     * @param array<string, array{ast: list<Node\Stmt>, source: string}>  $files
     * @return array<string, list<Diagnostic>> diagnostics keyed by URI/path
     */
    public function check(array $files, TypeHierarchy $hierarchy): array
    {
        $ctorByFqn = $this->indexConstructorsByFqn($files);
        $diagnosticsByFile = array_fill_keys(array_keys($files), []);

        foreach ($files as $path => $entry) {
            $positionMap = new PositionMap($entry['source']);
            $context = self::extractNamespaceAndUseMap($entry['ast']);
            $this->walkNewExpressions(
                $entry['ast'],
                $ctorByFqn,
                $hierarchy,
                $positionMap,
                $context['namespace'],
                $context['useMap'],
                $diagnosticsByFile[$path],
            );
        }
        return $diagnosticsByFile;
    }

    /**
     * Extract the file's enclosing namespace + the `use Foo\Bar [as
     * Baz]` map needed to resolve bare `Name` nodes to fully-qualified
     * class names without relying on nikic's NameResolver (which the
     * LSP's per-file Analyzer doesn't run).
     *
     * Handles both `Use_` and `GroupUse` (only the TYPE_NORMAL slots
     * -- function / const uses go through separate symbol tables and
     * don't bind class-like aliases).
     *
     * @param list<Node\Stmt> $ast
     * @return array{namespace: string, useMap: array<string, string>}
     */
    private static function extractNamespaceAndUseMap(array $ast): array
    {
        $namespace = '';
        $useMap = [];
        $topLevelStmts = $ast;
        foreach ($ast as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $namespace = $stmt->name === null ? '' : $stmt->name->toString();
                $topLevelStmts = $stmt->stmts;
                break;
            }
        }
        foreach ($topLevelStmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Use_) {
                foreach ($stmt->uses as $useUse) {
                    $type = $useUse->type !== Node\Stmt\Use_::TYPE_UNKNOWN
                        ? $useUse->type
                        : $stmt->type;
                    if ($type !== Node\Stmt\Use_::TYPE_NORMAL) {
                        continue;
                    }
                    $useMap[$useUse->getAlias()->toString()] = $useUse->name->toString();
                }
                continue;
            }
            if ($stmt instanceof Node\Stmt\GroupUse) {
                $prefix = $stmt->prefix->toString();
                foreach ($stmt->uses as $useUse) {
                    $type = $useUse->type !== Node\Stmt\Use_::TYPE_UNKNOWN
                        ? $useUse->type
                        : $stmt->type;
                    if ($type !== Node\Stmt\Use_::TYPE_NORMAL) {
                        continue;
                    }
                    $useMap[$useUse->getAlias()->toString()] = $prefix . '\\' . $useUse->name->toString();
                }
            }
        }
        return ['namespace' => $namespace, 'useMap' => $useMap];
    }

    /**
     * Resolve a `Name` node to an FQN given the file's namespace and
     * use map.  Handles the three nikic-classified shapes:
     *
     *   - fully-qualified `\App\Foo` → strip leading slash;
     *   - relative `namespace\Foo` → prepend file namespace;
     *   - unqualified / qualified `Foo` / `Foo\Bar` → consult use map
     *     for the head segment, otherwise prepend file namespace.
     *
     * @param array<string, string> $useMap
     */
    public function resolveNameToFqn(Name $name, string $namespace, array $useMap): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }
        $parts = $name->getParts();
        if ($parts === []) {
            return '';
        }
        $head = $parts[0];
        if (isset($useMap[$head])) {
            $tail = array_slice($parts, 1);
            return $tail === []
                ? $useMap[$head]
                : $useMap[$head] . '\\' . implode('\\', $tail);
        }
        $local = implode('\\', $parts);
        return $namespace !== '' ? $namespace . '\\' . $local : $local;
    }

    /**
     * Build a `App\Models\User` -> `{ctor, owner}` map by walking
     * every ClassLike across the workspace.  Carries the owning
     * ClassLike alongside the ClassMethod so the substitution map
     * builder can read the template's ATTR_GENERIC_PARAMS without
     * re-walking.
     *
     * FQN derivation: the LSP's per-file Analyzer does NOT run
     * nikic's NameResolver, so `namespacedName` isn't attached.  We
     * compute the FQN manually from the top-level `Namespace_`
     * wrapper instead -- cheaper than running NameResolver per-file
     * and avoids cloning the AST.
     *
     * Anonymous classes and classes whose constructor isn't declared
     * (the implicit zero-arg ctor) are skipped -- nothing for the
     * checker to compare against.
     *
     * @param array<string, array{ast: list<Node\Stmt>, source: string}> $files
     * @return array<string, array{ctor: ClassMethod, owner: ClassLike, namespace: string, useMap: array<string, string>}>
     */
    private function indexConstructorsByFqn(array $files): array
    {
        $byFqn = [];
        foreach ($files as $entry) {
            $context = self::extractNamespaceAndUseMap($entry['ast']);
            foreach (self::collectClassLikesWithNamespace($entry['ast']) as [$namespace, $cls]) {
                if ($cls->name === null) {
                    continue;
                }
                $shortName = $cls->name->toString();
                $fqn = $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;
                foreach ($cls->stmts as $member) {
                    if ($member instanceof ClassMethod && strtolower($member->name->toString()) === '__construct') {
                        $byFqn[$fqn] = [
                            'ctor' => $member,
                            'owner' => $cls,
                            'namespace' => $namespace,
                            'useMap' => $context['useMap'],
                        ];
                        break;
                    }
                }
            }
        }
        return $byFqn;
    }

    /**
     * Recursively walk the top-level statement list collecting every
     * `ClassLike` paired with its enclosing namespace string (empty
     * when the file has no `namespace` declaration).  Handles both
     * the "bracketed" form (`namespace App { ... }`) and the
     * "semicolon" form (`namespace App; ...`).
     *
     * @param list<Node\Stmt> $stmts
     * @return list<array{0: string, 1: ClassLike}>
     */
    private static function collectClassLikesWithNamespace(array $stmts): array
    {
        $out = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $ns = $stmt->name === null ? '' : $stmt->name->toString();
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof ClassLike) {
                        $out[] = [$ns, $inner];
                    }
                }
                continue;
            }
            if ($stmt instanceof ClassLike) {
                $out[] = ['', $stmt];
            }
        }
        return $out;
    }

    /**
     * @param list<Node\Stmt>                                               $ast
     * @param array<string, array{ctor: ClassMethod, owner: ClassLike}>     $ctorByFqn
     * @param array<string, string>                                         $useMap
     * @param list<Diagnostic>                                              $diagnostics
     */
    private function walkNewExpressions(
        array $ast,
        array $ctorByFqn,
        TypeHierarchy $hierarchy,
        PositionMap $positionMap,
        string $namespace,
        array $useMap,
        array &$diagnostics,
    ): void {
        $checker = $this;
        $visitor = new class($ctorByFqn, $hierarchy, $positionMap, $namespace, $useMap, $diagnostics, $checker) extends NodeVisitorAbstract {
            /**
             * @param array<string, array{ctor: ClassMethod, owner: ClassLike}> $ctorByFqn
             * @param array<string, string>                                     $useMap
             * @param list<Diagnostic>                                          $diagnostics
             */
            public function __construct(
                private readonly array $ctorByFqn,
                private readonly TypeHierarchy $hierarchy,
                private readonly PositionMap $positionMap,
                private readonly string $namespace,
                private readonly array $useMap,
                public array &$diagnostics,
                private readonly ConstructorArgumentChecker $checker,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if (!$node instanceof New_) {
                    return null;
                }
                if (!$node->class instanceof Name) {
                    return null;
                }
                $fqn = $this->checker->resolveTargetClassFqn($node->class, $this->namespace, $this->useMap);
                if ($fqn === '' || !isset($this->ctorByFqn[$fqn])) {
                    return null;
                }
                $entry = $this->ctorByFqn[$fqn];
                $substitution = $this->checker->buildSubstitution($node->class, $entry['owner']);
                $this->checker->emitMismatchDiagnostics(
                    $node,
                    $entry['ctor'],
                    $substitution,
                    $this->hierarchy,
                    $this->positionMap,
                    $this->namespace,
                    $this->useMap,
                    $entry['namespace'],
                    $entry['useMap'],
                    $this->diagnostics,
                    $fqn,
                );
                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }

    /**
     * Resolve the target class FQN of a `new C(…)` expression.
     * Prefers the xphp-parser-attached `ATTR_TEMPLATE_FQN` (set for
     * generic `new C<T>(…)` shapes), then resolves bare names via the
     * call site's namespace + use map.  Returns the empty string when
     * nothing can be resolved.
     *
     * @param array<string, string> $useMap
     */
    public function resolveTargetClassFqn(Name $classExpr, string $namespace, array $useMap): string
    {
        $generic = $classExpr->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
        if (is_string($generic) && $generic !== '') {
            return ltrim($generic, '\\');
        }
        return $this->resolveNameToFqn($classExpr, $namespace, $useMap);
    }

    /**
     * Build the type-parameter substitution map for a `new C<T1, T2>(…)`
     * instantiation by pairing the template's declared TypeParams with
     * the call site's TypeRefs.  Returns an empty map for non-generic
     * calls (no substitution needed).
     *
     * @return array<string, TypeRef>
     */
    public function buildSubstitution(Name $classExpr, ClassLike $owner): array
    {
        $args = $classExpr->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS);
        if (!is_array($args) || $args === []) {
            return [];
        }
        $params = $owner->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
        if (!is_array($params) || count($params) !== count($args)) {
            return [];
        }
        $names = self::extractTypeParamNames($params);
        if (count($names) !== count($args)) {
            return [];
        }
        $substitution = [];
        foreach ($names as $i => $paramName) {
            $arg = $args[$i];
            if ($arg instanceof TypeRef) {
                $substitution[$paramName] = $arg;
            }
        }
        return $substitution;
    }

    /**
     * @param array<int, mixed> $params
     * @return list<string>
     */
    private static function extractTypeParamNames(array $params): array
    {
        $names = [];
        foreach ($params as $p) {
            if (is_object($p) && property_exists($p, 'name') && is_string($p->name)) {
                $names[] = $p->name;
            }
        }
        return $names;
    }

    /**
     * Compare each call argument against its corresponding (substituted)
     * constructor parameter type.  Emits one Diagnostic per mismatch.
     *
     * @param array<string, TypeRef> $substitution
     * @param array<string, string>  $callerUseMap
     * @param array<string, string>  $ownerUseMap
     * @param list<Diagnostic>       $diagnostics
     */
    public function emitMismatchDiagnostics(
        New_ $newExpr,
        ClassMethod $ctor,
        array $substitution,
        TypeHierarchy $hierarchy,
        PositionMap $positionMap,
        string $callerNamespace,
        array $callerUseMap,
        string $ownerNamespace,
        array $ownerUseMap,
        array &$diagnostics,
        string $classFqn,
    ): void {
        $params = $ctor->params;
        foreach ($newExpr->args as $i => $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }
            $param = self::paramAtIndex($params, $i);
            if ($param === null) {
                continue;
            }
            $expectedType = $this->extractParamType($param, $substitution, $ownerNamespace, $ownerUseMap);
            if ($expectedType === null) {
                continue;
            }
            $actualType = $this->inferArgType($arg->value, $callerNamespace, $callerUseMap);
            if ($actualType === null) {
                continue;
            }
            if (self::isSatisfied($actualType, $expectedType, $hierarchy)) {
                continue;
            }
            $diagnostics[] = self::buildMismatchDiagnostic(
                $arg->value,
                $positionMap,
                $i + 1,
                $param,
                $expectedType,
                $actualType,
                $classFqn,
            );
        }
    }

    /**
     * Resolve the param record for a given positional argument index,
     * honoring variadics (last param consumes all trailing args).
     *
     * @param list<Param> $params
     */
    private static function paramAtIndex(array $params, int $index): ?Param
    {
        if (isset($params[$index])) {
            return $params[$index];
        }
        $last = $params[count($params) - 1] ?? null;
        if ($last !== null && $last->variadic) {
            return $last;
        }
        return null;
    }

    /**
     * Extract the param's declared type as a normalized display string,
     * applying type-arg substitution when the param type references a
     * generic type parameter.  Returns null when the param has no
     * type hint.
     *
     * For union / intersection types, returns the rendered form
     * (`A|B` / `A&B`).  Nullable types are rendered with the leading
     * `?`.
     *
     * The `$namespace` + `$useMap` are the OWNER's (the declaring
     * class's), not the call site's -- non-generic class-type params
     * resolve in the declaring file's import context.
     *
     * @param array<string, TypeRef> $substitution
     * @param array<string, string>  $useMap
     */
    private function extractParamType(Param $param, array $substitution, string $namespace, array $useMap): ?string
    {
        $type = $param->type;
        if ($type === null) {
            return null;
        }
        return $this->renderType($type, $substitution, $namespace, $useMap);
    }

    /**
     * @param array<string, TypeRef> $substitution
     * @param array<string, string>  $useMap
     */
    private function renderType(Node $type, array $substitution, string $namespace, array $useMap): string
    {
        if ($type instanceof NullableType) {
            return '?' . $this->renderType($type->type, $substitution, $namespace, $useMap);
        }
        if ($type instanceof Node\UnionType) {
            $parts = array_map(fn (Node $t): string => $this->renderType($t, $substitution, $namespace, $useMap), $type->types);
            return implode('|', $parts);
        }
        if ($type instanceof Node\IntersectionType) {
            $parts = array_map(fn (Node $t): string => $this->renderType($t, $substitution, $namespace, $useMap), $type->types);
            return implode('&', $parts);
        }
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }
        if ($type instanceof Name) {
            $raw = ltrim($type->toString(), '\\');
            // Generic type-param substitution: param `T $x` resolves
            // to whatever the instantiation passed for T.
            if (isset($substitution[$raw])) {
                return ltrim($substitution[$raw]->name, '\\');
            }
            // Bare scalar / reserved type names (`string`, `int`,
            // `self`, etc.) stay as-is -- no FQN resolution.
            if ($type->isUnqualified() && self::isReservedTypeName($raw)) {
                return $raw;
            }
            return $this->resolveNameToFqn($type, $namespace, $useMap);
        }
        return '';
    }

    /**
     * Recognises PHP's reserved scalar / pseudo type names that
     * shouldn't be FQN-resolved against the use map.
     */
    private static function isReservedTypeName(string $name): bool
    {
        $lower = strtolower($name);
        return isset(self::SCALARS[$lower])
            || isset(self::PERMISSIVE_TYPES[$lower])
            || $lower === 'null'
            || $lower === 'true'
            || $lower === 'false';
    }

    /**
     * AST-only argument type inference.  Returns null when the static
     * type isn't visible from the expression alone (variables,
     * method-call results, etc.).
     *
     * @param array<string, string> $useMap
     */
    private function inferArgType(Expr $expr, string $namespace, array $useMap): ?string
    {
        if ($expr instanceof New_) {
            if (!$expr->class instanceof Name) {
                return null;
            }
            // Generic instantiation: ATTR_TEMPLATE_FQN gives the
            // template FQN; for the satisfaction check we compare
            // against the template name, not the mangled
            // specialization name -- that lines up with the param's
            // pre-substitution type.
            $templateFqn = $expr->class->getAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN);
            if (is_string($templateFqn) && $templateFqn !== '') {
                return ltrim($templateFqn, '\\');
            }
            return $this->resolveNameToFqn($expr->class, $namespace, $useMap);
        }
        if ($expr instanceof String_) {
            return 'string';
        }
        if ($expr instanceof Int_) {
            return 'int';
        }
        if ($expr instanceof Float_) {
            return 'float';
        }
        if ($expr instanceof Array_) {
            return 'array';
        }
        if ($expr instanceof ConstFetch) {
            $name = strtolower($expr->name->toString());
            if ($name === 'true' || $name === 'false') {
                return 'bool';
            }
            if ($name === 'null') {
                return 'null';
            }
        }
        return null;
    }

    /**
     * Check whether `$actual` satisfies `$expected`.  See the class
     * docblock for the supported shapes.
     */
    private static function isSatisfied(string $actual, string $expected, TypeHierarchy $hierarchy): bool
    {
        $expected = ltrim($expected, '\\');
        $actual = ltrim($actual, '\\');

        if ($expected === '' || $actual === '') {
            return true;
        }
        // Nullable param: null is always OK; non-null is checked
        // against the inner type.
        if (str_starts_with($expected, '?')) {
            if ($actual === 'null') {
                return true;
            }
            return self::isSatisfied($actual, substr($expected, 1), $hierarchy);
        }
        if ($actual === 'null') {
            // Non-nullable param with null arg: explicit mismatch.
            return false;
        }
        if (str_contains($expected, '|')) {
            foreach (explode('|', $expected) as $arm) {
                if (self::isSatisfied($actual, $arm, $hierarchy)) {
                    return true;
                }
            }
            return false;
        }
        if (str_contains($expected, '&')) {
            foreach (explode('&', $expected) as $arm) {
                if (!self::isSatisfied($actual, $arm, $hierarchy)) {
                    return false;
                }
            }
            return true;
        }
        $expectedLower = strtolower($expected);
        if (isset(self::PERMISSIVE_TYPES[$expectedLower])) {
            return true;
        }
        if ($expected === $actual) {
            return true;
        }
        // Scalar param: arg type must match exactly.  `int -> float`
        // promotion is technically allowed by PHP but reporting it
        // is rarely useful as a warning -- accept the literal `int`
        // for a `float` param to avoid false positives.
        if (isset(self::SCALARS[$expectedLower])) {
            if ($expectedLower === 'float' && $actual === 'int') {
                return true;
            }
            if (isset(self::SCALARS[strtolower($actual)])) {
                return $expectedLower === strtolower($actual);
            }
            // Scalar expected, class supplied → mismatch.
            return false;
        }
        // Both sides are class-like.  Defer to the workspace's
        // TypeHierarchy.  Unknown ancestry -> assume OK (don't false-
        // positive on closed-source vendor types).
        $is = $hierarchy->isSubtype($actual, $expected);
        return $is !== false;
    }

    /**
     * @param Param $param
     */
    private static function buildMismatchDiagnostic(
        Expr $argExpr,
        PositionMap $positionMap,
        int $oneBasedIndex,
        Param $param,
        string $expectedType,
        string $actualType,
        string $classFqn,
    ): Diagnostic {
        $paramName = $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
            ? '$' . $param->var->name
            : '#' . $oneBasedIndex;
        $message = sprintf(
            'Constructor argument %d (%s) of %s expects %s, got %s.',
            $oneBasedIndex,
            $paramName,
            $classFqn,
            $expectedType,
            $actualType,
        );
        if ($argExpr->getStartFilePos() >= 0 && $argExpr->getEndFilePos() >= 0) {
            [$sl, $sc, $el, $ec] = $positionMap->rangeFromOffsets(
                $argExpr->getStartFilePos(),
                $argExpr->getEndFilePos() + 1,
            );
        } else {
            [$sl, $sc, $el, $ec] = $positionMap->fullLineRangeFromNikic($argExpr->getStartLine());
        }
        return new Diagnostic(
            startLine: $sl,
            startCharacter: $sc,
            endLine: $el,
            endCharacter: $ec,
            message: $message,
            code: DiagnosticCode::ConstructorArgumentMismatch,
        );
    }
}
