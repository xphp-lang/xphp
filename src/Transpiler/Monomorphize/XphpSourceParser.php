<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpToken;
use RuntimeException;

/**
 * Parses .xphp source text into an AST with generic metadata, supporting
 * arbitrarily nested generic type expressions in any type-hint position.
 *
 * Strategy:
 *  1. Tokenize the source with PhpToken (handles strings/comments/identifiers correctly).
 *  2. Walk the token stream and detect two patterns, tracking byte offsets:
 *       - `class IDENT < TypeArgList >` → captured as a "class" marker with bare type-param names.
 *       - `NAME < TypeArgList >` in any other position → captured as a "name" marker carrying a `TypeRef` tree.
 *     Each detected clause is recorded along with the byte range of the entire `<...>` clause.
 *  3. Replace each `<...>` clause in the source with spaces of equal length so byte offsets / line numbers are preserved.
 *  4. Parse the cleaned source with nikic/php-parser's standard parser.
 *  5. Walk the AST, matching markers to AST nodes by (line, name) + order:
 *       - ClassLike nodes (Class_/Interface_/Trait_) ← class markers → attach `xphp:genericParams` (list<string>).
 *       - Name nodes                                  ← name markers  → attach `xphp:genericArgs`  (list<TypeRef>).
 *  6. Resolve TypeRef names in attached args against the file's namespace + use statements + enclosing template's type-params.
 *
 * Also lowers the `Name[]` array-type sugar (any name, including type-params and class names,
 * optionally chained as `Name[][]…`) into the native `array` type-hint by emitting the literal
 * text `array` over the entire `Name[]…` range. The replacement is variable-length but never
 * introduces or removes a newline, so line numbers — which is how markers are matched to AST
 * nodes — stay stable.
 *
 * MVP limitations:
 *  - Generic syntax inside strings/comments is correctly ignored (tokenizer handles it).
 *  - Generic syntax with constraints (`T: SomeInterface`) is not supported.
 *  - `Name<Args>[]` (array of a generic) is not supported — generics-after-array-sugar would
 *    need extra wiring; users get a native PHP parse error today.
 */
final class XphpSourceParser
{
    public const ATTR_GENERIC_PARAMS = 'xphp:genericParams';
    public const ATTR_GENERIC_ARGS = 'xphp:genericArgs';
    public const ATTR_TEMPLATE_FQN = 'xphp:templateFqn';

    // Method-scoped generics (one type-param set per method, distinct from any class-level set).
    public const ATTR_METHOD_GENERIC_PARAMS = 'xphp:methodGenericParams';
    public const ATTR_METHOD_GENERIC_ARGS = 'xphp:methodGenericArgs';

    public const SCALAR_TYPES = [
        'int', 'integer', 'string', 'bool', 'boolean', 'float', 'double',
        'void', 'mixed', 'never', 'null', 'false', 'true',
        'array', 'iterable', 'object', 'callable', 'self', 'static', 'parent',
    ];

    public function __construct(private readonly Parser $parser)
    {
    }

    /**
     * @return list<Node\Stmt>
     */
    public function parse(string $source): array
    {
        [$classMarkers, $nameMarkers, $methodMarkers, $cleanedSource] = $this->scanAndStrip($source);

        $ast = $this->parser->parse($cleanedSource);
        if ($ast === null) {
            throw new RuntimeException('Parser returned null AST.');
        }

        $this->resolveAndAttach($ast, $classMarkers, $nameMarkers, $methodMarkers);

        return $ast;
    }

    /**
     * @return array{0: list<array{line:int, name:string, params:list<array{name:string, boundName:?string, boundIsFq:bool}>}>, 1: list<array{line:int, name:string, args:list<TypeRef>}>, 2: list<array{line:int, name:string, params:list<array{name:string, boundName:?string, boundIsFq:bool}>}>, 3: string}
     */
    private function scanAndStrip(string $source): array
    {
        $tokens = self::splitMergedAngleTokens(PhpToken::tokenize($source));
        $n = count($tokens);

        $classMarkers = [];
        $nameMarkers = [];
        $methodMarkers = [];
        /** @var list<array{int, int, string}> $replacements [byte offset, original length, replacement text] */
        $replacements = [];

        $i = 0;
        while ($i < $n) {
            $tok = $tokens[$i];

            if ($tok->id === T_FUNCTION) {
                $j = self::skipWs($tokens, $i + 1);
                if ($j < $n && $tokens[$j]->id === T_STRING) {
                    $methodName = $tokens[$j]->text;
                    $methodLine = $tokens[$j]->line;
                    $k = self::skipWs($tokens, $j + 1);
                    if ($k < $n && $tokens[$k]->text === '<') {
                        $parsed = self::parseTypeParamList($tokens, $k);
                        if ($parsed !== null) {
                            [$paramEntries, $endIdx] = $parsed;
                            $methodMarkers[] = [
                                'line' => $methodLine,
                                'name' => $methodName,
                                'params' => $paramEntries,
                            ];
                            $startByte = $tokens[$k]->pos;
                            $endByte = $tokens[$endIdx]->pos + strlen($tokens[$endIdx]->text);
                            $length = $endByte - $startByte;
                            $replacements[] = [$startByte, $length, str_repeat(' ', $length)];
                            $i = $endIdx + 1;
                            continue;
                        }
                    }
                }
                $i++;
                continue;
            }

            if ($tok->id === T_CLASS || $tok->id === T_INTERFACE || $tok->id === T_TRAIT) {
                $j = self::skipWs($tokens, $i + 1);
                if ($j < $n && $tokens[$j]->id === T_STRING) {
                    $className = $tokens[$j]->text;
                    $classLine = $tokens[$j]->line;
                    $k = self::skipWs($tokens, $j + 1);
                    if ($k < $n && $tokens[$k]->text === '<') {
                        $parsed = self::parseTypeParamList($tokens, $k);
                        if ($parsed !== null) {
                            [$paramEntries, $endIdx] = $parsed;
                            $classMarkers[] = [
                                'line' => $classLine,
                                'name' => $className,
                                'params' => $paramEntries,
                            ];
                            $startByte = $tokens[$k]->pos;
                            $endByte = $tokens[$endIdx]->pos + strlen($tokens[$endIdx]->text);
                            $length = $endByte - $startByte;
                            $replacements[] = [$startByte, $length, str_repeat(' ', $length)];
                            $i = $endIdx + 1;
                            continue;
                        }
                    }
                }
                $i++;
                continue;
            }

            if (self::isNameToken($tok)) {
                $nameText = $tok->text;
                $nameLine = $tok->line;
                $j = self::skipWs($tokens, $i + 1);
                if ($j < $n && $tokens[$j]->text === '<') {
                    $parsed = self::parseTypeArgList($tokens, $j);
                    if ($parsed !== null) {
                        [$args, $endIdx] = $parsed;
                        $nameMarkers[] = [
                            'line' => $nameLine,
                            'name' => ltrim($nameText, '\\'),
                            'args' => $args,
                        ];
                        $startByte = $tokens[$j]->pos;
                        $endByte = $tokens[$endIdx]->pos + strlen($tokens[$endIdx]->text);
                        $length = $endByte - $startByte;
                        $replacements[] = [$startByte, $length, str_repeat(' ', $length)];
                        $i = $endIdx + 1;
                        continue;
                    }
                }

                // Skip the array-type sugar in member-access context (`$x->prop[]`,
                // `$x?->prop[]`, `Foo::bar[]`) — there the trailing `[]` is array-append
                // or array-deref syntax, never a type-hint.
                if (!self::isMemberAccessContext($tokens, $i)) {
                    $arraySuffixEnd = self::parseArraySuffix($tokens, $j);
                    if ($arraySuffixEnd !== null) {
                        $startByte = $tok->pos;
                        $endByte = $tokens[$arraySuffixEnd]->pos + strlen($tokens[$arraySuffixEnd]->text);
                        $replacements[] = [$startByte, $endByte - $startByte, 'array'];
                        $i = $arraySuffixEnd + 1;
                        continue;
                    }
                }
                $i++;
                continue;
            }

            $i++;
        }

        $cleaned = self::applyReplacements($source, $replacements);

        return [$classMarkers, $nameMarkers, $methodMarkers, $cleaned];
    }

    /**
     * Parse a class-header type-param list: `< Name(: Bound)? (, Name(: Bound)?)* >`.
     *
     * Unlike `parseTypeArgList` (which is for *instantiation* sites and only handles
     * concrete + nested-generic args), this variant only fires on the class/interface/trait
     * header — so the `:` after a name is unambiguous and signals a bound.
     *
     * Returns `[entries, endIdx]` where each entry is a `{name: string, boundName: ?string,
     * boundIsFq: bool}` record; later resolved to a TypeParam(name, ?boundFqn) inside the
     * AST traversal step (which has access to the namespace + use map).
     *
     * @param list<PhpToken> $tokens
     * @return array{0: list<array{name: string, boundName: ?string, boundIsFq: bool}>, 1: int}|null
     */
    private static function parseTypeParamList(array $tokens, int $openIdx): ?array
    {
        $n = count($tokens);
        if ($openIdx >= $n || $tokens[$openIdx]->text !== '<') {
            return null;
        }

        $entries = [];
        $i = self::skipWs($tokens, $openIdx + 1);
        while ($i < $n) {
            if (!self::isNameToken($tokens[$i])) {
                return null;
            }
            $paramName = ltrim($tokens[$i]->text, '\\');
            $i++;

            $boundName = null;
            $boundIsFq = false;
            $afterName = self::skipWs($tokens, $i);
            if ($afterName < $n && $tokens[$afterName]->text === ':') {
                $afterColon = self::skipWs($tokens, $afterName + 1);
                if ($afterColon >= $n || !self::isNameToken($tokens[$afterColon])) {
                    return null;
                }
                $boundText = $tokens[$afterColon]->text;
                $boundName = ltrim($boundText, '\\');
                $boundIsFq = str_starts_with($boundText, '\\');
                $i = $afterColon + 1;
            }

            $entries[] = [
                'name' => $paramName,
                'boundName' => $boundName,
                'boundIsFq' => $boundIsFq,
            ];

            $i = self::skipWs($tokens, $i);
            if ($i >= $n) {
                return null;
            }
            if ($tokens[$i]->text === '>') {
                return [$entries, $i];
            }
            if ($tokens[$i]->text === ',') {
                $i = self::skipWs($tokens, $i + 1);
                continue;
            }
            return null;
        }

        return null;
    }

    /**
     * Parse `< TypeArg (, TypeArg)* >` starting at the index of the `<` token.
     * Returns null if the clause is not a valid generic-args list (so the caller can treat `<` as the less-than operator).
     *
     * @param list<PhpToken> $tokens
     * @return array{0: list<TypeRef>, 1: int}|null  [parsed args, index of the closing `>` token]
     */
    private static function parseTypeArgList(array $tokens, int $openIdx): ?array
    {
        $n = count($tokens);
        if ($openIdx >= $n || $tokens[$openIdx]->text !== '<') {
            return null;
        }

        $args = [];
        $i = self::skipWs($tokens, $openIdx + 1);
        while ($i < $n) {
            $argResult = self::parseTypeArg($tokens, $i);
            if ($argResult === null) {
                return null;
            }
            [$arg, $i] = $argResult;
            $args[] = $arg;

            $i = self::skipWs($tokens, $i);
            if ($i >= $n) {
                return null;
            }
            if ($tokens[$i]->text === '>') {
                return [$args, $i];
            }
            if ($tokens[$i]->text === ',') {
                $i = self::skipWs($tokens, $i + 1);
                continue;
            }
            return null;
        }

        return null;
    }

    /**
     * Parse a single type arg: `NAME ( < TypeArgList > )?`.
     *
     * @param list<PhpToken> $tokens
     * @return array{0: TypeRef, 1: int}|null  [parsed TypeRef, index past the last consumed token]
     */
    private static function parseTypeArg(array $tokens, int $i): ?array
    {
        $n = count($tokens);
        if ($i >= $n || !self::isNameToken($tokens[$i])) {
            return null;
        }

        $name = ltrim($tokens[$i]->text, '\\');
        $isFq = str_starts_with($tokens[$i]->text, '\\');
        $i++;

        $j = self::skipWs($tokens, $i);
        if ($j < $n && $tokens[$j]->text === '<') {
            $listResult = self::parseTypeArgList($tokens, $j);
            if ($listResult === null) {
                return null;
            }
            [$nested, $endIdx] = $listResult;
            $resolvedName = $isFq ? '\\' . $name : $name;
            return [new TypeRef($resolvedName, $nested), $endIdx + 1];
        }

        $resolvedName = $isFq ? '\\' . $name : $name;
        return [new TypeRef($resolvedName), $i];
    }

    /**
     * Returns true when the Name token at `$nameIdx` is preceded by an `->`, `?->`, or `::`
     * operator — i.e. it's a property/method/constant reference, not a type-hint.
     *
     * @param list<PhpToken> $tokens
     */
    private static function isMemberAccessContext(array $tokens, int $nameIdx): bool
    {
        $i = $nameIdx - 1;
        while ($i >= 0 && ($tokens[$i]->id === T_WHITESPACE || $tokens[$i]->id === T_COMMENT || $tokens[$i]->id === T_DOC_COMMENT)) {
            $i--;
        }
        if ($i < 0) {
            return false;
        }
        return $tokens[$i]->id === T_OBJECT_OPERATOR
            || $tokens[$i]->id === T_NULLSAFE_OBJECT_OPERATOR
            || $tokens[$i]->id === T_DOUBLE_COLON;
    }

    /**
     * Detect zero or more chained `[]` suffixes starting at index `$i`. Returns the index of the
     * closing `]` of the last bracket pair, or `null` if no `[]` pair is found.
     *
     * Whitespace and comments between the brackets are tolerated (e.g. `T [ ]`).
     *
     * @param list<PhpToken> $tokens
     */
    private static function parseArraySuffix(array $tokens, int $i): ?int
    {
        $n = count($tokens);
        $lastBracketEnd = null;

        while ($i < $n && $tokens[$i]->text === '[') {
            $j = self::skipWs($tokens, $i + 1);
            if ($j >= $n || $tokens[$j]->text !== ']') {
                break;
            }
            $lastBracketEnd = $j;
            $i = self::skipWs($tokens, $j + 1);
        }

        return $lastBracketEnd;
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private static function skipWs(array $tokens, int $i): int
    {
        $n = count($tokens);
        while ($i < $n && ($tokens[$i]->id === T_WHITESPACE || $tokens[$i]->id === T_COMMENT || $tokens[$i]->id === T_DOC_COMMENT)) {
            $i++;
        }
        return $i;
    }

    /**
     * Split T_SR (`>>`) and T_SL (`<<`) into two synthetic single-char tokens so the
     * depth-tracking scanner sees the correct bracket structure inside nested generics
     * like `Box<List<Plastic>>`. Outside generic-args context this is harmless — we only
     * use the token stream for scanning, never feed it back to the PHP parser.
     *
     * @param list<PhpToken> $tokens
     * @return list<PhpToken>
     */
    private static function splitMergedAngleTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            if ($t->id === T_SR) {
                $out[] = new PhpToken(ord('>'), '>', $t->line, $t->pos);
                $out[] = new PhpToken(ord('>'), '>', $t->line, $t->pos + 1);
                continue;
            }
            if ($t->id === T_SL) {
                $out[] = new PhpToken(ord('<'), '<', $t->line, $t->pos);
                $out[] = new PhpToken(ord('<'), '<', $t->line, $t->pos + 1);
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    private static function isNameToken(PhpToken $tok): bool
    {
        return $tok->id === T_STRING
            || $tok->id === T_NAME_QUALIFIED
            || $tok->id === T_NAME_FULLY_QUALIFIED
            || $tok->id === T_NAME_RELATIVE;
    }

    /**
     * @param list<array{int, int, string}> $replacements [byte offset, original length, replacement text]
     */
    private static function applyReplacements(string $source, array $replacements): string
    {
        usort($replacements, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        foreach ($replacements as [$start, $length, $replacement]) {
            $source = substr($source, 0, $start) . $replacement . substr($source, $start + $length);
        }

        return $source;
    }

    /**
     * Walk the AST: attach markers to ClassLike and Name nodes by (line, name) + order; resolve TypeRef names.
     *
     * @param list<Node\Stmt> $ast
     * @param list<array{line:int, name:string, params:list<array{name:string, boundName:?string, boundIsFq:bool}>}> $classMarkers
     * @param list<array{line:int, name:string, args:list<TypeRef>}> $nameMarkers
     * @param list<array{line:int, name:string, params:list<array{name:string, boundName:?string, boundIsFq:bool}>}> $methodMarkers
     */
    private function resolveAndAttach(array $ast, array $classMarkers, array $nameMarkers, array $methodMarkers): void
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($classMarkers, $nameMarkers, $methodMarkers) extends NodeVisitorAbstract {
            private string $currentNamespace = '';
            /** @var array<string, string> alias → FQN */
            private array $useMap = [];
            /** @var list<list<string>> stack of enclosing type-param scopes */
            private array $typeParamStack = [];

            /**
             * @param list<array{line:int, name:string, params:list<array{name:string, boundName:?string, boundIsFq:bool}>}> $classMarkers
             * @param list<array{line:int, name:string, args:list<TypeRef>}> $nameMarkers
             * @param list<array{line:int, name:string, params:list<array{name:string, boundName:?string, boundIsFq:bool}>}> $methodMarkers
             */
            public function __construct(
                private array $classMarkers,
                private array $nameMarkers,
                private array $methodMarkers,
            ) {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    // @infection-ignore-all — namespace { ... } (no name) isn't used in any fixture.
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    $this->useMap = [];
                    // @infection-ignore-all — redundant with the standalone Use_ branch below; dead loop.
                    foreach ($node->stmts ?? [] as $inner) {
                        if ($inner instanceof Use_) {
                            $this->indexUses($inner);
                        }
                    }
                }

                if ($node instanceof Use_) {
                    // @infection-ignore-all — dual-handled by the inner foreach above.
                    $this->indexUses($node);
                }

                if ($node instanceof ClassLike && $node->name !== null) {
                    $shortName = $node->name->toString();
                    $paramEntries = null;
                    foreach ($this->classMarkers as $i => $marker) {
                        if ($marker['line'] === $node->getStartLine() && $marker['name'] === $shortName) {
                            $paramEntries = $marker['params'];
                            unset($this->classMarkers[$i]);
                            // @infection-ignore-all — break vs continue is equivalent after unset (marker is gone).
                            break;
                        }
                    }
                    if ($paramEntries !== null && $paramEntries !== []) {
                        $typeParams = [];
                        $paramNames = [];
                        foreach ($paramEntries as $entry) {
                            $boundFqn = null;
                            if ($entry['boundName'] !== null) {
                                $boundFqn = $entry['boundIsFq']
                                    ? $entry['boundName']
                                    : $this->resolveNameOnly($entry['boundName']);
                            }
                            $typeParams[] = new TypeParam($entry['name'], $boundFqn);
                            $paramNames[] = $entry['name'];
                        }
                        $node->setAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS, $typeParams);
                        $fqn = $this->currentNamespace !== ''
                            ? $this->currentNamespace . '\\' . $shortName
                            : $shortName;
                        $node->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, $fqn);
                        $this->typeParamStack[] = $paramNames;
                    } else {
                        $this->typeParamStack[] = [];
                    }
                }

                if ($node instanceof Node\Stmt\ClassMethod) {
                    $methodName = $node->name->toString();
                    foreach ($this->methodMarkers as $i => $marker) {
                        if ($marker['line'] === $node->getStartLine() && $marker['name'] === $methodName) {
                            $typeParams = [];
                            foreach ($marker['params'] as $entry) {
                                $boundFqn = null;
                                if ($entry['boundName'] !== null) {
                                    $boundFqn = $entry['boundIsFq']
                                        ? $entry['boundName']
                                        : $this->resolveNameOnly($entry['boundName']);
                                }
                                $typeParams[] = new TypeParam($entry['name'], $boundFqn);
                            }
                            $node->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS, $typeParams);
                            unset($this->methodMarkers[$i]);
                            // @infection-ignore-all — break vs continue is equivalent after unset (marker is gone).
                            break;
                        }
                    }
                }

                if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
                    $callMethodName = $node->name->toString();
                    foreach ($this->nameMarkers as $i => $marker) {
                        if ($marker['line'] === $node->getStartLine() && $marker['name'] === $callMethodName) {
                            $resolvedArgs = $this->resolveTypeRefList($marker['args']);
                            $node->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS, $resolvedArgs);
                            unset($this->nameMarkers[$i]);
                            // @infection-ignore-all — break vs continue is equivalent after unset (marker is gone).
                            break;
                        }
                    }
                }

                if ($node instanceof Name) {
                    $nameStr = $node->toString();
                    foreach ($this->nameMarkers as $i => $marker) {
                        if ($marker['line'] === $node->getStartLine() && $marker['name'] === $nameStr) {
                            $resolved = $this->resolveTypeRefList($marker['args']);
                            $node->setAttribute(XphpSourceParser::ATTR_GENERIC_ARGS, $resolved);
                            $templateFqn = $this->resolveNameOnly($nameStr);
                            $node->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, $templateFqn);
                            unset($this->nameMarkers[$i]);
                            // @infection-ignore-all — break vs continue is equivalent after unset (marker is gone).
                            break;
                        }
                    }
                }

                return null;
            }

            private function resolveNameOnly(string $name): string
            {
                if (str_starts_with($name, '\\')) {
                    return ltrim($name, '\\');
                }
                if ($this->isEnclosingTypeParam($name)) {
                    return $name;
                }
                $first = self::firstSegment($name);
                if (isset($this->useMap[$first])) {
                    $rest = substr($name, strlen($first));
                    return $this->useMap[$first] . $rest;
                }
                return $this->currentNamespace !== ''
                    ? $this->currentNamespace . '\\' . $name
                    : $name;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof ClassLike) {
                    array_pop($this->typeParamStack);
                }
                return null;
            }

            private function indexUses(Use_ $use): void
            {
                foreach ($use->uses as $u) {
                    if (!$u instanceof UseItem) {
                        continue;
                    }
                    $fqn = $u->name->toString();
                    $alias = $u->alias?->toString() ?? self::lastSegment($fqn);
                    $this->useMap[$alias] = $fqn;
                }
            }

            /**
             * @param list<TypeRef> $refs
             * @return list<TypeRef>
             */
            private function resolveTypeRefList(array $refs): array
            {
                return array_map(fn (TypeRef $r): TypeRef => $this->resolveTypeRef($r), $refs);
            }

            private function resolveTypeRef(TypeRef $ref): TypeRef
            {
                $resolvedArgs = $this->resolveTypeRefList($ref->args);
                $name = $ref->name;

                if (str_starts_with($name, '\\')) {
                    return new TypeRef(ltrim($name, '\\'), $resolvedArgs);
                }

                if ($this->isEnclosingTypeParam($name)) {
                    return new TypeRef($name, $resolvedArgs, isTypeParam: true);
                }

                // @infection-ignore-all — scalar-type tokens already arrive lowercased from PHP grammar.
                $lower = strtolower($name);
                if (in_array($lower, XphpSourceParser::SCALAR_TYPES, true)) {
                    return new TypeRef($lower, $resolvedArgs, isScalar: true);
                }

                $first = self::firstSegment($name);
                if (isset($this->useMap[$first])) {
                    $rest = substr($name, strlen($first));
                    return new TypeRef($this->useMap[$first] . $rest, $resolvedArgs);
                }

                $resolved = $this->currentNamespace !== ''
                    ? $this->currentNamespace . '\\' . $name
                    : $name;
                return new TypeRef($resolved, $resolvedArgs);
            }

            private function isEnclosingTypeParam(string $name): bool
            {
                foreach ($this->typeParamStack as $scope) {
                    if (in_array($name, $scope, true)) {
                        return true;
                    }
                }
                return false;
            }

            private static function lastSegment(string $name): string
            {
                $pos = strrpos($name, '\\');
                return $pos === false ? $name : substr($name, $pos + 1);
            }

            private static function firstSegment(string $name): string
            {
                $pos = strpos($name, '\\');
                return $pos === false ? $name : substr($name, 0, $pos);
            }
        });

        $traverser->traverse($ast);
    }
}
