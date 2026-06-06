<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
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
 * Call-site syntax follows PHP RFC bound_erased_generic_types: `Name::<Args>(...)`
 * turbofish at every expression-context call (`new`, free function, static method,
 * instance method). Bare `Name<Args>(...)` at a call site is rejected so the source
 * fails to compile rather than silently specializing into a form the future PHP
 * runtime would refuse. Declaration sites (`class Box<T>`) and type-hint positions
 * (`Box<T> $b`, `: Box<T>`, `extends Box<T>`) stay bare -- the RFC accepts both.
 *
 * MVP limitations:
 *  - Generic syntax inside strings/comments is correctly ignored (tokenizer handles it).
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
        return $this->parseWithMap($source)[0];
    }

    /**
     * Same as `parse()` but also returns the byte-offset map -- used by LSP
     * handlers that emit positions back to the client and need to translate
     * AST offsets (which are positioned in the stripped source) into the
     * original source's byte offsets.
     *
     * Returns the identity map when no length-changing replacements fired
     * (the common case for files without `T[]` array-suffix sugar).
     *
     * @return array{0: list<Node\Stmt>, 1: ByteOffsetMap}
     */
    public function parseWithMap(string $source): array
    {
        [$classMarkers, $nameMarkers, $methodMarkers, $cleanedSource, $byteOffsetMap] = $this->scanAndStrip($source);

        $ast = $this->parser->parse($cleanedSource);
        if ($ast === null) {
            throw new RuntimeException('Parser returned null AST.');
        }

        $this->resolveAndAttach($ast, $classMarkers, $nameMarkers, $methodMarkers);

        return [$ast, $byteOffsetMap];
    }

    /**
     * Same as `parse()` but with error recovery -- returns the best-effort
     * partial AST when the source has trailing syntax errors (typical
     * during interactive editing, e.g. cursor on `$x->|` with no
     * terminator).  Never throws.  Returns null only when the parser
     * couldn't produce any AST at all.
     *
     * xphp attributes (`ATTR_GENERIC_PARAMS`, `ATTR_GENERIC_ARGS`, etc.)
     * are attached to whatever subtrees did parse cleanly -- exactly what
     * the LSP needs to keep substituting type-args when the user is
     * mid-statement.
     *
     * @return list<Node\Stmt>|null
     */
    public function parseTolerant(string $source): ?array
    {
        return $this->parseTolerantWithMap($source)?->ast;
    }

    /**
     * Same as `parseTolerant()` but also returns the byte-offset map.
     * Returns null only when the parser couldn't produce any AST at all.
     */
    public function parseTolerantWithMap(string $source): ?ParseWithMapResult
    {
        [$classMarkers, $nameMarkers, $methodMarkers, $cleanedSource, $byteOffsetMap] = $this->scanAndStrip($source);

        $errorHandler = new \PhpParser\ErrorHandler\Collecting();
        $ast = $this->parser->parse($cleanedSource, $errorHandler);
        if ($ast === null) {
            return null;
        }

        $this->resolveAndAttach($ast, $classMarkers, $nameMarkers, $methodMarkers);

        return new ParseWithMapResult($ast, $byteOffsetMap);
    }

    /**
     * Return the xphp source with every `<…>` generic clause (template
     * params on class/interface/trait/method headers AND type-args on
     * generic-call sites) replaced by equal-length whitespace.  The result
     * is valid PHP that nikic/php-parser or any other PHP-only tool
     * (e.g. phpactor/tolerant-php-parser via worse-reflection) can ingest
     * without choking.  Byte offsets in the cleaned source are identical
     * to the original xphp source, so locations round-trip back to the
     * editor cleanly.
     *
     * Infallible -- the underlying tokenizer never throws on malformed
     * PHP; pathological inputs simply produce a stripped source with no
     * generic clauses recognised.
     */
    public function strip(string $source): string
    {
        return $this->scanAndStrip($source)[3];
    }

    /**
     * @return array{0: list<array{line:int, name:string, params:list<array{name:string, bound:?array}>}>, 1: list<array{line:int, anchorLine:int, name:string, args:list<TypeRef>}>, 2: list<array{line:int, name:string, params:list<array{name:string, bound:?array}>}>, 3: string, 4: ByteOffsetMap}
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

            // Anonymous closure: `function<T>(...){}` or
            // `static function<T>(...){}`. Recognized by T_FUNCTION followed
            // immediately by `<` (no T_STRING name). For `static function<T>`
            // the leading T_STATIC was consumed in the same arm.
            if ($tok->id === T_FUNCTION || $tok->id === T_FN) {
                $isArrow = $tok->id === T_FN;
                $anchorByte = $tok->pos;
                $anchorLine = $tok->line;
                $j = self::skipWs($tokens, $i + 1);
                if ($j < $n && $tokens[$j]->text === '<') {
                    // P5.7: defaults allowed on anonymous closures + arrows
                    // because their specialization paths shipped in
                    // P5.5 / P5.6. `padArgsWithDefaults` at GMC call-site
                    // time pads missing trailing args.
                    $parsed = self::parseTypeParamList(
                        $tokens,
                        $j,
                        allowDefaults: true,
                        allowVariance: false,
                    );
                    if ($parsed !== null) {
                        [$paramEntries, $endIdx] = $parsed;
                        $methodMarkers[] = [
                            'line' => $anchorLine,
                            'name' => '',
                            'kind' => $isArrow ? 'arrow' : 'closure',
                            'bytePosition' => $anchorByte,
                            'params' => $paramEntries,
                        ];
                        $startByte = $tokens[$j]->pos;
                        $endByte = $tokens[$endIdx]->pos + strlen($tokens[$endIdx]->text);
                        $length = $endByte - $startByte;
                        $replacements[] = [$startByte, $length, str_repeat(' ', $length)];
                        $i = $endIdx + 1;
                        continue;
                    }
                }
                // Not a closure with generic params -- fall through to the
                // named-function path (T_FUNCTION) or skip the token (T_FN).
            }

            // `static function<T>(...)` -- the leading T_STATIC must be
            // recognized so we can include it in the anchor byte position.
            if ($tok->id === T_STATIC) {
                $j = self::skipWs($tokens, $i + 1);
                if ($j < $n && $tokens[$j]->id === T_FUNCTION) {
                    $k = self::skipWs($tokens, $j + 1);
                    if ($k < $n && $tokens[$k]->text === '<') {
                        $parsed = self::parseTypeParamList(
                            $tokens,
                            $k,
                            allowDefaults: false,
                            allowVariance: false,
                        );
                        if ($parsed !== null) {
                            [$paramEntries, $endIdx] = $parsed;
                            $methodMarkers[] = [
                                'line' => $tok->line,
                                'name' => '',
                                'kind' => 'staticClosure',
                                'bytePosition' => $tok->pos,
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
                // Not a generic static closure -- fall through.
            }

            if ($tok->id === T_FUNCTION) {
                $j = self::skipWs($tokens, $i + 1);
                if ($j < $n && $tokens[$j]->id === T_STRING) {
                    $methodName = $tokens[$j]->text;
                    $methodLine = $tokens[$j]->line;
                    $methodAnchorByte = $tokens[$j]->pos;
                    $k = self::skipWs($tokens, $j + 1);
                    if ($k < $n && $tokens[$k]->text === '<') {
                        $parsed = self::parseTypeParamList(
                            $tokens,
                            $k,
                            allowDefaults: true,
                            allowVariance: false,
                        );
                        if ($parsed !== null) {
                            [$paramEntries, $endIdx] = $parsed;
                            $methodMarkers[] = [
                                'line' => $methodLine,
                                'name' => $methodName,
                                'kind' => 'named',
                                'bytePosition' => $methodAnchorByte,
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
                    $classAnchorByte = $tokens[$j]->pos;
                    $k = self::skipWs($tokens, $j + 1);
                    if ($k < $n && $tokens[$k]->text === '<') {
                        $parsed = self::parseTypeParamList(
                            $tokens,
                            $k,
                            allowDefaults: true,
                            allowVariance: true,
                        );
                        if ($parsed !== null) {
                            [$paramEntries, $endIdx] = $parsed;
                            $classMarkers[] = [
                                'line' => $classLine,
                                'name' => $className,
                                'kind' => 'named',
                                'bytePosition' => $classAnchorByte,
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

            // Variable turbofish: `$var::<int>(...)` -- the call-site shape for
            // generic closures and arrow functions. nikic parses this as
            // `FuncCall(name: Variable, args: [...])`; the marker's name is the
            // variable identifier (no `$`) so the resolver's Variable arm can
            // match.
            if ($tok->id === T_VARIABLE) {
                $j = self::skipWs($tokens, $i + 1);
                if ($j < $n && $tokens[$j]->id === T_DOUBLE_COLON) {
                    $dcTok = $tokens[$j];
                    $afterDc = $j + 1;
                    $isEmptyTurbofish = $afterDc < $n
                        && $tokens[$afterDc]->id === T_IS_NOT_EQUAL
                        && $tokens[$afterDc]->pos === $dcTok->pos + 2;
                    $parsed = null;
                    if ($isEmptyTurbofish) {
                        $parsed = [[], $afterDc];
                    } elseif ($afterDc < $n
                        && $tokens[$afterDc]->text === '<'
                        && $tokens[$afterDc]->pos === $dcTok->pos + 2
                    ) {
                        $parsed = self::parseTypeArgList($tokens, $afterDc);
                    }
                    if ($parsed !== null) {
                        [$args, $endIdx] = $parsed;
                        $varName = substr($tok->text, 1); // strip the leading `$`
                        $nameMarkers[] = [
                            'line' => $tok->line,
                            'anchorLine' => $tok->line,
                            'name' => $varName,
                            'kind' => 'variableTurbofish',
                            'bytePosition' => $tok->pos,
                            'args' => $args,
                        ];
                        $startByte = $dcTok->pos;
                        $endByte = $tokens[$endIdx]->pos + strlen($tokens[$endIdx]->text);
                        $length = $endByte - $startByte;
                        $replacements[] = [$startByte, $length, str_repeat(' ', $length)];
                        $i = $endIdx + 1;
                        continue;
                    }
                }
                $i++;
                continue;
            }

            // `static` is a PHP keyword (T_STATIC), not a name token, but the
            // RFC treats `static<T>` (and the sibling `self<T>` / `parent<T>`
            // pseudo-types) as valid type-hint positions. `self` / `parent` are
            // T_STRING and fall through the next branch naturally; `static`
            // needs an explicit gate here.
            if (self::isNameToken($tok) || $tok->id === T_STATIC) {
                $nameText = $tok->text;
                $nameLine = $tok->line;
                // For member-access call sites (`Foo::method::<…>`, `$x->method::<…>`,
                // `$x?->method::<…>`), walk back past the operator to the receiver and
                // record its line as the marker's anchor. nikic sets a MethodCall /
                // StaticCall's getStartLine() to the leftmost token in the chain — so
                // matching against just the identifier's line breaks the moment the
                // operator+name are split across lines, e.g. `Foo::\n    method::<int>`.
                // The resolver matches if startLine ∈ [anchorLine, line].
                $anchorLine = self::memberAccessReceiverLine($tokens, $i) ?? $nameLine;
                $j = self::skipWs($tokens, $i + 1);

                // Turbofish `Name::<…>` -- the RFC-mandated call-site form.
                // Whitespace-sensitive between `::` and `<`: enforced by requiring the
                // `<` token's byte position to sit immediately after `::`. Whitespace
                // between the Name and `::` is fine (PHP allows it for member access).
                if ($j < $n && $tokens[$j]->id === T_DOUBLE_COLON) {
                    $dcTok = $tokens[$j];
                    $afterDc = $j + 1;
                    // PHP's tokenizer keeps `<>` as a single T_IS_NOT_EQUAL token
                    // (the legacy != operator). Immediately after `::`, that's the
                    // empty-turbofish all-defaults shape `Foo::<>` -- recognized here
                    // by token-id rather than splitting upstream (splitting unconditionally
                    // would break legitimate `$x <> $y` comparisons elsewhere).
                    $isEmptyTurbofish = $afterDc < $n
                        && $tokens[$afterDc]->id === T_IS_NOT_EQUAL
                        && $tokens[$afterDc]->pos === $dcTok->pos + 2;
                    $parsed = null;
                    if ($isEmptyTurbofish) {
                        $parsed = [[], $afterDc];
                    } elseif ($afterDc < $n
                        && $tokens[$afterDc]->text === '<'
                        && $tokens[$afterDc]->pos === $dcTok->pos + 2
                    ) {
                        $parsed = self::parseTypeArgList($tokens, $afterDc);
                    }
                    if ($parsed !== null) {
                        [$args, $endIdx] = $parsed;
                        // Pseudo-type turbofish (`new self::<T>()`, `new parent::<T>()`,
                        // `new static::<T>()`) -- strip the `::<...>` clause so PHP
                        // can parse the source, but SKIP the marker. Otherwise the
                        // resolver would attach ATTR_TEMPLATE_FQN = `App\…\self` and
                        // CallSiteRewriter would try to specialize a template that
                        // doesn't exist. The bare `new self()` / `new parent()` /
                        // `new static()` survives unchanged; PHP's runtime resolves
                        // it against the currently-executing specialized class.
                        //
                        // Static-method calls on pseudo-types (`self::method::<T>()`,
                        // `static::method::<T>()`, `parent::method::<T>()`) are
                        // unaffected -- those land on `method`, not on the leading
                        // self/static/parent, and GMC's `resolveClassName` already
                        // short-circuits the pseudo-type to currentClassFqn.
                        //
                        // Note: `new parent::<T>()` on a class whose parent is NOT
                        // generic strips cleanly and PHP runtime decides validity.
                        // We don't validate the parent-is-generic invariant here.
                        if (!self::isPseudoType($nameText)) {
                            // Instance-method turbofish (`$obj->m::<…>(...)`) markers are
                            // claimed by the MethodCall / NullsafeMethodCall resolver branch
                            // alongside StaticCall (item #11). GenericMethodCompiler does
                            // receiver-type analysis to pick the right method template.
                            $nameMarkers[] = [
                                'line' => $nameLine,
                                'anchorLine' => $anchorLine,
                                'name' => ltrim($nameText, '\\'),
                                'kind' => 'named',
                                'bytePosition' => $tok->pos,
                                'args' => $args,
                            ];
                        }
                        // Strip from `::` start through `>` end so the cleaned
                        // source reads as a plain `Name(...)` / `Recv::Name(...)`
                        // / `$obj->Name(...)` call.
                        $startByte = $dcTok->pos;
                        $endByte = $tokens[$endIdx]->pos + strlen($tokens[$endIdx]->text);
                        $length = $endByte - $startByte;
                        $replacements[] = [$startByte, $length, str_repeat(' ', $length)];
                        $i = $endIdx + 1;
                        continue;
                    }
                }

                // Bare `Name<…>` is only valid in type-hint position (param/return/
                // property types, `extends` / `implements` clauses). In expression
                // context bare `<` is comparison; call sites must use the `::<…>`
                // turbofish per RFC. Heuristic: the position is an expression-context
                // call site if the `>` is followed by `(` (function-call open) OR if
                // the Name is preceded by `new` (catches the parenless `new Foo<T>;`
                // and `new Foo<T>` shapes that PHP accepts but the RFC turbofish
                // requirement refuses). Type-hint sites match neither check.
                if ($j < $n && $tokens[$j]->text === '<') {
                    $parsed = self::parseTypeArgList($tokens, $j);
                    if ($parsed !== null) {
                        [$args, $endIdx] = $parsed;
                        $afterClose = self::skipWs($tokens, $endIdx + 1);
                        $isCallSite = ($afterClose < $n && $tokens[$afterClose]->text === '(')
                            || self::isPrecededByNew($tokens, $i);
                        if (!$isCallSite) {
                            // Pseudo-types (`self<T>` / `static<T>` / `parent<T>`):
                            // strip the `<…>` clause so PHP can parse the source, but
                            // skip the marker -- otherwise the resolver would attach
                            // ATTR_GENERIC_ARGS to the bare `self` Name and the
                            // Registry would try to specialize a non-existent
                            // `App\…\self` template (which is the very bug that took
                            // the strip-only fix from b88539c's review). With the
                            // marker dropped, monomorphization on the enclosing
                            // class -- which already specialized it with concrete
                            // type args -- carries the `self` reference through
                            // unchanged; PHP's runtime resolves it to the right
                            // specialized class.
                            if (!self::isPseudoType($nameText)) {
                                $nameMarkers[] = [
                                    'line' => $nameLine,
                                    'anchorLine' => $anchorLine,
                                    'name' => ltrim($nameText, '\\'),
                                    'kind' => 'named',
                                    'bytePosition' => $tok->pos,
                                    'args' => $args,
                                ];
                            }
                            $startByte = $tokens[$j]->pos;
                            $endByte = $tokens[$endIdx]->pos + strlen($tokens[$endIdx]->text);
                            $length = $endByte - $startByte;
                            $replacements[] = [$startByte, $length, str_repeat(' ', $length)];
                            $i = $endIdx + 1;
                            continue;
                        }
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
        $byteOffsetMap = ByteOffsetMap::fromReplacements($replacements);

        return [$classMarkers, $nameMarkers, $methodMarkers, $cleaned, $byteOffsetMap];
    }

    /**
     * Parse a class-header type-param list: `< Name(: Bound)?(= Default)? (, ...)* >`.
     *
     * Unlike `parseTypeArgList` (which is for *instantiation* sites and only handles
     * concrete + nested-generic args), this variant only fires on the class/interface/trait
     * header (or, when `$allowDefaults` is false, on a method/function header) — so the
     * `:` after a name unambiguously signals a bound and `=` unambiguously signals a default.
     *
     * Returns `[entries, endIdx]` where each entry is a `{name, bound, default}` record:
     *   - bound (when present) is a structured tree:
     *       leaf:         `['kind' => 'leaf', 'name' => string, 'isFq' => bool, 'args' => list<TypeRef>]`
     *       intersection: `['kind' => 'and',  'operands' => list<bound>]`
     *       union:        `['kind' => 'or',   'operands' => list<bound>]`
     *   - default (when present) is an unresolved `TypeRef`. The resolver pass
     *     applies namespace + use-map resolution and marks `isTypeParam: true`
     *     on references to earlier params in the same list.
     *
     * When `$allowDefaults` is false (method / function headers), `= Default` is
     * rejected with a clear error pointing at the limitation.
     *
     * Defaulted parameters must be trailing (required follows defaulted is rejected
     * at parse time). A default cannot reference the same param or a later one
     * (forward references are rejected at parse time).
     *
     * @param list<PhpToken> $tokens
     * @return array{0: list<array{name: string, bound: ?array, default: ?TypeRef, variance: Variance}>, 1: int}|null
     */
    private static function parseTypeParamList(
        array $tokens,
        int $openIdx,
        bool $allowDefaults,
        bool $allowVariance,
    ): ?array {
        $n = count($tokens);
        if ($openIdx >= $n || $tokens[$openIdx]->text !== '<') {
            return null;
        }

        $entries = [];
        $sawDefault = false;
        $i = self::skipWs($tokens, $openIdx + 1);
        while ($i < $n) {
            // Variance prefix `+` (covariant) or `-` (contravariant). Both are
            // single-char tokens at this position. Class-level only -- methods,
            // functions, closures, and arrow functions reject them because
            // their specializations aren't keyed by stable identities that
            // PHP would resolve via `extends` chains.
            $variance = Variance::Invariant;
            if ($i < $n && ($tokens[$i]->text === '+' || $tokens[$i]->text === '-')) {
                if (!$allowVariance) {
                    throw new RuntimeException(
                        'Variance markers `+T` / `-T` are not yet supported on '
                        . 'methods, functions, closures, or arrow functions; '
                        . 'move the generic to a class-level type parameter.',
                    );
                }
                $variance = $tokens[$i]->text === '+'
                    ? Variance::Covariant
                    : Variance::Contravariant;
                $i++;
            }

            if (!self::isNameToken($tokens[$i])) {
                return null;
            }
            $paramName = ltrim($tokens[$i]->text, '\\');
            $i++;

            $bound = null;
            $afterName = self::skipWs($tokens, $i);
            if ($afterName < $n && $tokens[$afterName]->text === ':') {
                $afterColon = self::skipWs($tokens, $afterName + 1);
                $parsedBound = self::parseBoundExpr($tokens, $afterColon);
                if ($parsedBound === null) {
                    return null;
                }
                [$bound, $i] = $parsedBound;
            }

            $default = null;
            $afterBound = self::skipWs($tokens, $i);
            if ($afterBound < $n && $tokens[$afterBound]->text === '=') {
                if (!$allowDefaults) {
                    throw new RuntimeException(sprintf(
                        'Generic parameter `%s` has a default value, which is not yet '
                        . 'supported on static closures. Drop the `static` modifier or '
                        . 'assign the closure to a named function.',
                        $paramName,
                    ));
                }
                $afterEq = self::skipWs($tokens, $afterBound + 1);
                $parsedDefault = self::parseTypeArg($tokens, $afterEq);
                if ($parsedDefault === null) {
                    throw new RuntimeException(sprintf(
                        'Generic parameter `%s` has an invalid default; only a single '
                        . 'concrete or generic type is allowed after `=` (no nullable '
                        . 'or union shapes).',
                        $paramName,
                    ));
                }
                [$default, $i] = $parsedDefault;
                // Reject `T = Box | Other` (union) and `T = Box & Other` (intersection)
                // explicitly so users see the "no nullable or union shapes" message
                // rather than the surrounding class header silently failing to be
                // recognized as generic and PHP emitting a downstream syntax error.
                $afterDefault = self::skipWs($tokens, $i);
                if ($afterDefault < $n
                    && ($tokens[$afterDefault]->text === '|' || $tokens[$afterDefault]->text === '&')
                ) {
                    throw new RuntimeException(sprintf(
                        'Generic parameter `%s` has an invalid default; only a single '
                        . 'concrete or generic type is allowed after `=` (no nullable '
                        . 'or union shapes).',
                        $paramName,
                    ));
                }
                $sawDefault = true;
            } elseif ($sawDefault) {
                throw new RuntimeException(sprintf(
                    'Generic parameter `%s` has no default but follows a parameter with '
                    . 'a default. Required type parameters must precede defaulted ones.',
                    $paramName,
                ));
            }

            $entries[] = [
                'name' => $paramName,
                'bound' => $bound,
                'default' => $default,
                'variance' => $variance,
            ];

            $i = self::skipWs($tokens, $i);
            if ($i >= $n) {
                return null;
            }
            if ($tokens[$i]->text === '>') {
                self::assertNoTopLevelSelfReference($entries);
                self::assertDefaultsReferenceOnlyEarlierParams($entries);
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
     * Reject `class Bad<T = T>` (default references self) and `class Bad<T = U, U>`
     * (default references a later param). A default may reference *strictly earlier*
     * params in the same list; `class Pair<A, B = A>` is allowed.
     *
     * The check runs at parse time against the raw source-level names on the
     * default's TypeRef tree. A leading-`\\` (e.g. `T = \T` where `\T` is a global
     * class named T) is allowed because the FQ form unambiguously refers to a
     * class and not the same-named type-param.
     *
     * @param list<array{name: string, bound: ?array, default: ?TypeRef}> $entries
     */
    private static function assertDefaultsReferenceOnlyEarlierParams(array $entries): void
    {
        $paramNames = array_map(
            static fn (array $e): string => $e['name'],
            $entries,
        );
        foreach ($entries as $idx => $entry) {
            if ($entry['default'] === null) {
                continue;
            }
            self::assertDefaultRefsEarlierOnly(
                $entry['default'],
                $entry['name'],
                $paramNames,
                $idx,
            );
        }
    }

    /**
     * Recursively walk a default TypeRef tree. For any leaf whose name (after
     * stripping leading `\\`) matches a param name at index `>= $currentIdx`,
     * throw with a clear error. A leading-`\\` short-circuits the check (FQ
     * names refer to classes, not type-params).
     *
     * @param list<string> $paramNames
     */
    private static function assertDefaultRefsEarlierOnly(
        TypeRef $ref,
        string $currentParam,
        array $paramNames,
        int $currentIdx,
    ): void {
        if (!str_starts_with($ref->name, '\\')) {
            $refIdx = array_search($ref->name, $paramNames, true);
            if ($refIdx !== false && $refIdx >= $currentIdx) {
                $msg = $refIdx === $currentIdx
                    ? sprintf(
                        'Generic parameter `%s` cannot reference itself in its default. '
                        . 'Use `\\%s` to reference a global class named %s, if that was '
                        . 'intended.',
                        $currentParam,
                        $currentParam,
                        $currentParam,
                    )
                    : sprintf(
                        'Generic parameter `%s` default references `%s`, which is declared '
                        . 'later in the same parameter list. Defaults may only reference '
                        . 'strictly earlier parameters.',
                        $currentParam,
                        $ref->name,
                    );
                throw new RuntimeException($msg);
            }
        }
        foreach ($ref->args as $inner) {
            self::assertDefaultRefsEarlierOnly($inner, $currentParam, $paramNames, $currentIdx);
        }
    }

    /**
     * RFC bound-erased generic types forbids `class A<T : T>` -- a type parameter
     * cannot use *itself* as a bound at the top level. F-bounded recursion
     * (`class A<T : Box<T>>`) is fine because the inner T is a generic argument
     * to a different type; only the bare-self case is rejected.
     *
     * The check fires for any leaf bound (including operands inside
     * intersection / union) that bare-name-matches the param. `isFq` filters
     * out `\T` (a global class named T), which is a real class reference
     * rather than a type-parameter self-reference. Leaves with non-empty
     * `args` are F-bounded shapes (`T : Box<T>`) and are explicitly allowed.
     *
     * @param list<array{name: string, bound: ?array}> $entries
     */
    private static function assertNoTopLevelSelfReference(array $entries): void
    {
        foreach ($entries as $entry) {
            if ($entry['bound'] === null) {
                continue;
            }
            if (self::boundContainsSelfReference($entry['bound'], $entry['name'])) {
                // The guard fires for a bare-self leaf at ANY depth inside the
                // bound tree, not just the outermost position. The message
                // intentionally does NOT say "top-level" -- that wording would
                // be misleading when the guard fires on `T : Foo | T` or
                // `T : (A & T) | B` where the bare-self leaf is an operand.
                throw new RuntimeException(sprintf(
                    'Generic parameter `%s` cannot use itself as a bound '
                    . '(self-reference detected in the bound expression). '
                    . 'Use a nested form like `%s : Box<%s>` for F-bounded '
                    . 'recursion, or remove the bound.',
                    $entry['name'],
                    $entry['name'],
                    $entry['name'],
                ));
            }
        }
    }

    /**
     * Recursively check whether a bound tree contains a bare top-level
     * self-reference (a leaf whose name equals `$paramName`, isn't fully
     * qualified, and has no generic args).
     *
     * @param array{kind: string, ...} $bound
     */
    private static function boundContainsSelfReference(array $bound, string $paramName): bool
    {
        if ($bound['kind'] === 'leaf') {
            return $bound['name'] === $paramName
                && !$bound['isFq']
                && $bound['args'] === [];
        }
        foreach ($bound['operands'] as $operand) {
            if (self::boundContainsSelfReference($operand, $paramName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recursive-descent parser for bound expressions:
     *
     *     bound      := orBound
     *     orBound    := andBound ('|' andBound)*
     *     andBound   := primary ('&' primary)*
     *     primary    := '(' bound ')' | leaf
     *     leaf       := Name typeArgList?      // typeArgList for F-bounded
     *
     * Returns `[bound_array, newIdx]` on success or null if the input from
     * `$startIdx` isn't a well-formed bound expression. Single-operand `or`
     * and `and` collapse to their inner operand so a plain `T : Foo` stays
     * a leaf in the resulting tree.
     *
     * @param list<PhpToken> $tokens
     * @return array{0: array, 1: int}|null
     */
    private static function parseBoundExpr(array $tokens, int $startIdx): ?array
    {
        return self::parseOrBound($tokens, $startIdx);
    }

    /**
     * @param list<PhpToken> $tokens
     * @return array{0: array, 1: int}|null
     */
    private static function parseOrBound(array $tokens, int $idx): ?array
    {
        $first = self::parseAndBound($tokens, $idx);
        if ($first === null) {
            return null;
        }
        [$result, $idx] = $first;
        $operands = [$result];

        while (true) {
            $peek = self::skipWs($tokens, $idx);
            if ($peek >= count($tokens) || $tokens[$peek]->text !== '|') {
                break;
            }
            $next = self::parseAndBound($tokens, self::skipWs($tokens, $peek + 1));
            if ($next === null) {
                return null;
            }
            [$rhs, $idx] = $next;
            $operands[] = $rhs;
        }

        if (count($operands) === 1) {
            return [$operands[0], $idx];
        }
        return [['kind' => 'or', 'operands' => $operands], $idx];
    }

    /**
     * @param list<PhpToken> $tokens
     * @return array{0: array, 1: int}|null
     */
    private static function parseAndBound(array $tokens, int $idx): ?array
    {
        $first = self::parsePrimaryBound($tokens, $idx);
        if ($first === null) {
            return null;
        }
        [$result, $idx] = $first;
        $operands = [$result];

        while (true) {
            $peek = self::skipWs($tokens, $idx);
            if ($peek >= count($tokens) || $tokens[$peek]->text !== '&') {
                break;
            }
            $next = self::parsePrimaryBound($tokens, self::skipWs($tokens, $peek + 1));
            if ($next === null) {
                return null;
            }
            [$rhs, $idx] = $next;
            $operands[] = $rhs;
        }

        if (count($operands) === 1) {
            return [$operands[0], $idx];
        }
        return [['kind' => 'and', 'operands' => $operands], $idx];
    }

    /**
     * @param list<PhpToken> $tokens
     * @return array{0: array, 1: int}|null
     */
    private static function parsePrimaryBound(array $tokens, int $idx): ?array
    {
        $n = count($tokens);
        if ($idx >= $n) {
            return null;
        }
        if ($tokens[$idx]->text === '(') {
            $inner = self::parseBoundExpr($tokens, self::skipWs($tokens, $idx + 1));
            if ($inner === null) {
                return null;
            }
            [$boundInside, $afterInner] = $inner;
            $closeIdx = self::skipWs($tokens, $afterInner);
            if ($closeIdx >= $n || $tokens[$closeIdx]->text !== ')') {
                return null;
            }
            return [$boundInside, $closeIdx + 1];
        }
        return self::parseLeafBound($tokens, $idx);
    }

    /**
     * Leaf bound: a name token, optionally followed by a `< TypeArgList >` for
     * F-bounded forms. The args use `parseTypeArgList` (the same machinery
     * used at instantiation sites) so nested generic args resolve correctly.
     *
     * @param list<PhpToken> $tokens
     * @return array{0: array, 1: int}|null
     */
    private static function parseLeafBound(array $tokens, int $idx): ?array
    {
        $n = count($tokens);
        if ($idx >= $n || !self::isNameToken($tokens[$idx])) {
            return null;
        }
        $rawName = $tokens[$idx]->text;
        $idx++;

        $args = [];
        $afterName = self::skipWs($tokens, $idx);
        if ($afterName < $n && $tokens[$afterName]->text === '<') {
            $parsed = self::parseTypeArgList($tokens, $afterName);
            if ($parsed === null) {
                // The `<` wasn't a generic-args opener; backtrack and treat
                // the name as a plain leaf.
                return [
                    [
                        'kind' => 'leaf',
                        'name' => ltrim($rawName, '\\'),
                        'isFq' => str_starts_with($rawName, '\\'),
                        'args' => [],
                    ],
                    $idx,
                ];
            }
            [$args, $endIdx] = $parsed;
            $idx = $endIdx + 1;
        }

        return [
            [
                'kind' => 'leaf',
                'name' => ltrim($rawName, '\\'),
                'isFq' => str_starts_with($rawName, '\\'),
                'args' => $args,
            ],
            $idx,
        ];
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
        // Empty turbofish: `Foo::<>` -- the all-defaults call-site shape.
        // Returning an empty args list here lets the registry's padding pick
        // up every defaulted param. A non-defaulted template surfaces as the
        // padding error at recordInstantiation time.
        if ($i < $n && $tokens[$i]->text === '>') {
            return [$args, $i];
        }
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
     * If the Name at `$nameIdx` is preceded by `->` / `?->` / `::`, walk back past the
     * operator and skippable tokens and return the receiver's source line. Otherwise
     * return null.
     *
     * Used to anchor name-markers (for `Foo::method<int>` style call sites) to the
     * receiver's line — which is what `StaticCall::getStartLine()` /
     * `MethodCall::getStartLine()` return. Without this, splitting the operator and
     * the method name across lines would desync the marker line from the AST line
     * and the marker would never attach. See the F2 finding for the failure mode.
     *
     * Note: doesn't try to traverse arbitrary chained expressions
     * (`$a->b()->method<int>` etc.) — those would need balanced-paren backtracking.
     * For the common single-receiver case (variable or class name) this is enough
     * and any chained-receiver shape would already not have matched under the old
     * line-equality check.
     *
     * @infection-ignore-all — the body is a flat token walk: skippable-token boundary
     * mutations either OOB-error (suppressed because nikic would have failed to
     * parse the wider invalid input first) or toggle the operator-id triple-or
     * (T_OBJECT_OPERATOR || T_NULLSAFE_OBJECT_OPERATOR || T_DOUBLE_COLON), which
     * shifts a defensive guard whose alternate branch is unreachable from any
     * valid call-site syntax. Same shape and rationale as `isMemberAccessContext`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function memberAccessReceiverLine(array $tokens, int $nameIdx): ?int
    {
        $i = $nameIdx - 1;
        while ($i >= 0 && ($tokens[$i]->id === T_WHITESPACE || $tokens[$i]->id === T_COMMENT || $tokens[$i]->id === T_DOC_COMMENT)) {
            $i--;
        }
        if ($i < 0) {
            return null;
        }
        $operatorId = $tokens[$i]->id;
        if ($operatorId !== T_OBJECT_OPERATOR
            && $operatorId !== T_NULLSAFE_OBJECT_OPERATOR
            && $operatorId !== T_DOUBLE_COLON
        ) {
            return null;
        }
        $i--;
        while ($i >= 0 && ($tokens[$i]->id === T_WHITESPACE || $tokens[$i]->id === T_COMMENT || $tokens[$i]->id === T_DOC_COMMENT)) {
            $i--;
        }
        if ($i < 0) {
            return null;
        }
        return $tokens[$i]->line;
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
     * Returns true when the Name token at `$nameIdx` is preceded by `new` (with
     * optional whitespace / comments in between).
     *
     * Used to reject bare `<…>` at `new`-expression sites that don't have a
     * trailing `(` -- specifically `new Foo<T>;` and `new Foo<T>` shapes that
     * PHP itself allows. Without this check, the bare-`<…>` heuristic only
     * catches the parens-bearing form (`new Foo<T>()`), so the parenless
     * variants slip through and xphp silently specializes a form the RFC
     * turbofish requirement would refuse.
     *
     * @infection-ignore-all — flat token walk, same shape as
     * `memberAccessReceiverLine` / `isMemberAccessContext`. Boundary mutations
     * (`-1` → `-2`, `>=` → `>`) only differ at the very first token, which is
     * always T_OPEN_TAG and not skippable -- nikic would have rejected any
     * source where the walk could underflow before this code runs. The
     * triple-`||` split (skippable-token check) only matters between comment
     * tokens, which the bare-`<>` rejection still catches via the parens arm.
     *
     * @param list<PhpToken> $tokens
     */
    private static function isPrecededByNew(array $tokens, int $nameIdx): bool
    {
        $i = $nameIdx - 1;
        while ($i >= 0 && ($tokens[$i]->id === T_WHITESPACE || $tokens[$i]->id === T_COMMENT || $tokens[$i]->id === T_DOC_COMMENT)) {
            $i--;
        }
        return $i >= 0 && $tokens[$i]->id === T_NEW;
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
     * `self` / `static` / `parent` are PHP keywords that resolve dynamically
     * at runtime against the currently-executing class. xphp's scanner sees
     * them in two positions that need special handling:
     *
     *  - Type-hint position (`function f(): self<T>`): strip the `<T>` so PHP
     *    can parse the source, but DON'T record a marker -- the resolver
     *    would otherwise attach ATTR_TEMPLATE_FQN = `App\…\self` and the
     *    Registry would fail looking up the (non-existent) template.
     *
     *  - Constructor-turbofish position (`new self::<T>()`): same shape --
     *    strip `::<T>` but skip the marker. Monomorphization on the
     *    enclosing class preserves the bare `self` / `static` / `parent`
     *    reference, and PHP's runtime resolves it.
     *
     * `self` / `parent` / `static` are case-insensitive PHP keywords --
     * `new SELF::<T>()` and `new Self::<T>()` parse the same as the
     * lowercase form. `strtolower` + strict literal comparison covers
     * all spellings the parser accepts; pinned by
     * `testTurbofishOnMixedCaseSelfIsStrippedWithoutMarker`.
     */
    private static function isPseudoType(string $name): bool
    {
        return in_array(
            strtolower($name),
            ['self', 'static', 'parent'],
            true,
        );
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
     * Marker entries carry both a `name` (for legacy (line, name) matching on
     * named templates) and a `bytePosition` of the anchor token in the
     * source. The `kind` field tags the marker so anonymous-template
     * recognition (closures / arrows, Phase 4) can dispatch to a different
     * matcher without having to peek at the rest of the marker shape.
     *
     * @param list<Node\Stmt> $ast
     * @param list<array{line:int, name:string, kind:string, bytePosition:int, params:list<array{name:string, bound:?array}>}> $classMarkers
     * @param list<array{line:int, anchorLine:int, name:string, kind:string, bytePosition:int, args:list<TypeRef>}> $nameMarkers
     * @param list<array{line:int, name:string, kind:string, bytePosition:int, params:list<array{name:string, bound:?array}>}> $methodMarkers
     */
    private function resolveAndAttach(array $ast, array $classMarkers, array $nameMarkers, array $methodMarkers): void
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($classMarkers, $nameMarkers, $methodMarkers) extends NodeVisitorAbstract {
            private NamespaceContext $ctx;
            /** @var list<list<string>> stack of enclosing type-param scopes */
            private array $typeParamStack = [];

            /**
             * @param list<array{line:int, name:string, kind:string, bytePosition:int, params:list<array{name:string, bound:?array}>}> $classMarkers
             * @param list<array{line:int, anchorLine:int, name:string, kind:string, bytePosition:int, args:list<TypeRef>}> $nameMarkers
             * @param list<array{line:int, name:string, kind:string, bytePosition:int, params:list<array{name:string, bound:?array}>}> $methodMarkers
             */
            public function __construct(
                private array $classMarkers,
                private array $nameMarkers,
                private array $methodMarkers,
            ) {
                $this->ctx = new NamespaceContext();
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    // @infection-ignore-all — bare `namespace { ... }` (no name) isn't used in any
                    // fixture; the null-coalesce branch never observably differs from a missing name.
                    $this->ctx->enterNamespace($node->name?->toString());
                    // @infection-ignore-all — redundant with the standalone Use_ branch below; dead loop.
                    foreach ($node->stmts ?? [] as $inner) {
                        if ($inner instanceof Use_) {
                            $this->ctx->indexUse($inner);
                        }
                    }
                }

                if ($node instanceof Use_) {
                    // @infection-ignore-all — dual-handled by the inner foreach above.
                    $this->ctx->indexUse($node);
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
                        // Two-pass: push the param names onto the resolution
                        // scope BEFORE building bounds. This lets F-bounded
                        // bounds (`T : Box<T>`) resolve the inner T as a
                        // type-param reference rather than qualifying it to
                        // `App\T` (which would happen via resolveNameOnly's
                        // namespace fallback).
                        $paramNames = array_map(
                            static fn (array $entry): string => $entry['name'],
                            $paramEntries,
                        );
                        $this->typeParamStack[] = $paramNames;
                        $typeParams = [];
                        foreach ($paramEntries as $entry) {
                            $bound = $this->buildBoundExpr($entry);
                            $default = $this->buildDefault($entry);
                            $typeParams[] = new TypeParam(
                                $entry['name'],
                                $bound,
                                $default,
                                $entry['variance'],
                            );
                        }
                        // ATTR_GENERIC_PARAMS is set on enterNode so
                        // leaveNode (where the variance position validator
                        // runs) can read it back. The body's nested
                        // ATTR_GENERIC_ARGS aren't populated until the
                        // resolver walks each Name node, which only happens
                        // BETWEEN this class's enterNode and leaveNode --
                        // hence the deferred validation.
                        $node->setAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS, $typeParams);
                        $currentNamespace = $this->ctx->currentNamespace();
                        $fqn = $currentNamespace !== ''
                            ? $currentNamespace . '\\' . $shortName
                            : $shortName;
                        $node->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, $fqn);
                    } else {
                        $this->typeParamStack[] = [];
                    }
                }

                if ($node instanceof Node\Stmt\ClassMethod
                    || $node instanceof Node\Stmt\Function_
                    || $node instanceof Node\Expr\Closure
                    || $node instanceof Node\Expr\ArrowFunction
                ) {
                    // Named templates match by (line, name); anonymous templates
                    // (closures + arrows) match by (line, bytePosition) -- the
                    // bytePosition recorded at the `function` / `static` / `fn`
                    // keyword aligns with nikic's `getStartFilePos()` for the
                    // same AST node.
                    $isAnonymous = $node instanceof Node\Expr\Closure
                        || $node instanceof Node\Expr\ArrowFunction;
                    $declName = $isAnonymous ? '' : $node->name->toString();
                    $nodeStartByte = $node->getStartFilePos();
                    $matchedParamNames = [];
                    foreach ($this->methodMarkers as $i => $marker) {
                        // @infection-ignore-all -- markers are populated jointly with
                        // both halves of the (line, name) or (kind, bytePosition) pair;
                        // any single-clause-only input is unreachable from the scanner.
                        $isMatch = $isAnonymous
                            ? ($marker['kind'] !== 'named'
                                && $marker['bytePosition'] === $nodeStartByte)
                            : ($marker['line'] === $node->getStartLine()
                                && $marker['name'] === $declName);
                        if ($isMatch) {
                            // Same two-pass scope-push-before-bound-build pattern
                            // as the ClassLike branch above, so F-bounded method
                            // generics see their own T on the resolution stack.
                            $matchedParamNames = array_map(
                                static fn (array $entry): string => $entry['name'],
                                $marker['params'],
                            );
                            $this->typeParamStack[] = $matchedParamNames;
                            $typeParams = [];
                            foreach ($marker['params'] as $entry) {
                                $bound = $this->buildBoundExpr($entry);
                                // Method / function / closure / arrow entries
                                // never carry variance markers
                                // (parseTypeParamList rejects with
                                // `allowVariance: false`). Defaults are allowed
                                // on methods, functions, anonymous closures
                                // (P5.7), and arrows (P5.7); only `static`
                                // closures still reject defaults at parse time
                                // because their specialization path doesn't ship.
                                $default = $this->buildDefault($entry);
                                $typeParams[] = new TypeParam(
                                    $entry['name'],
                                    $bound,
                                    $default,
                                    $entry['variance'],
                                );
                            }
                            // @infection-ignore-all -- pop here; the method/closure's
                            // own scope is pushed again below to match the leaveNode
                            // pop pattern. Dropping this pop leaves an extra entry on
                            // the stack that's symmetric-popped at leaveNode time, so
                            // observable stack state at the next sibling is identical
                            // for every test fixture (no nested same-name shadowing).
                            array_pop($this->typeParamStack);
                            $node->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_PARAMS, $typeParams);
                            unset($this->methodMarkers[$i]);
                            // @infection-ignore-all — break vs continue is equivalent after unset (marker is gone).
                            break;
                        }
                    }
                    // Push method/function/closure type-params (possibly empty)
                    // onto the resolution scope so that bare `T` inside the body
                    // resolves to an isTypeParam TypeRef instead of being qualified
                    // to `App\T`. Always push so the leaveNode pop has a 1:1
                    // counterpart, matching the ClassLike shape.
                    $this->typeParamStack[] = $matchedParamNames;
                }

                if (($node instanceof Node\Expr\StaticCall
                        || $node instanceof Node\Expr\MethodCall
                        || $node instanceof Node\Expr\NullsafeMethodCall)
                    && $node->name instanceof Node\Identifier
                ) {
                    $callMethodName = $node->name->toString();
                    $startLine = $node->getStartLine();
                    foreach ($this->nameMarkers as $i => $marker) {
                        // Match by name + line-range overlap. The Call node's
                        // getStartLine() is the receiver's line; the marker's anchorLine
                        // is the same, and its (later) line is the identifier's line.
                        // Both can differ on multi-line `Foo::\n    method::<int>` or
                        // `$obj->\n    method::<int>` constructs. The same logic now
                        // covers static, instance, and nullsafe method calls -- the
                        // GenericMethodCompiler distinguishes them later by AST type.
                        if ($marker['name'] === $callMethodName
                            && $startLine >= $marker['anchorLine']
                            && $startLine <= $marker['line']
                        ) {
                            $resolvedArgs = $this->resolveTypeRefList($marker['args']);
                            $node->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS, $resolvedArgs);
                            unset($this->nameMarkers[$i]);
                            // @infection-ignore-all — break vs continue is equivalent after unset (marker is gone).
                            break;
                        }
                    }
                }

                if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Name) {
                    // Claim the marker on FuncCall enter (parent fires before children) so the
                    // inner Name doesn't pick it up and trigger the class-rewrite path.
                    $funcName = $node->name->toString();
                    $startLine = $node->getStartLine();
                    foreach ($this->nameMarkers as $i => $marker) {
                        // @infection-ignore-all -- the three `&&` clauses are jointly
                        // populated when a marker is created; any single-clause-only
                        // input is unreachable from XphpSourceParser's own scanner.
                        if ($marker['name'] === $funcName
                            && $startLine >= $marker['anchorLine']
                            && $startLine <= $marker['line']
                            && $marker['kind'] !== 'variableTurbofish'
                        ) {
                            $resolvedArgs = $this->resolveTypeRefList($marker['args']);
                            $node->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS, $resolvedArgs);
                            $node->setAttribute(XphpSourceParser::ATTR_TEMPLATE_FQN, $this->resolveNameOnly($funcName));
                            unset($this->nameMarkers[$i]);
                            // @infection-ignore-all — break vs continue is equivalent after unset (marker is gone).
                            break;
                        }
                    }
                }

                // Variable-turbofish call site: `$var::<...>(...)` -- nikic
                // parses this (after the scanner stripped `::<...>`) as
                // `FuncCall(name: Variable, args: [...])`. The marker's name
                // field stores the variable identifier (no `$`).
                if ($node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Expr\Variable
                    && is_string($node->name->name)
                ) {
                    $varName = $node->name->name;
                    $startLine = $node->getStartLine();
                    foreach ($this->nameMarkers as $i => $marker) {
                        // @infection-ignore-all -- markers are populated jointly:
                        // kind/name/anchorLine/line are all set together by the
                        // scanner's variable-turbofish arm, so single-clause
                        // dropouts are unreachable.
                        if ($marker['kind'] === 'variableTurbofish'
                            && $marker['name'] === $varName
                            && $startLine >= $marker['anchorLine']
                            && $startLine <= $marker['line']
                        ) {
                            $resolvedArgs = $this->resolveTypeRefList($marker['args']);
                            $node->setAttribute(XphpSourceParser::ATTR_METHOD_GENERIC_ARGS, $resolvedArgs);
                            unset($this->nameMarkers[$i]);
                            // @infection-ignore-all — break vs continue is equivalent after unset.
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
                return $this->ctx->resolveAgainstContext($name);
            }

            /**
             * Build a `BoundExpr` from a parsed type-param entry. Recursively
             * walks the bound tree produced by `parseBoundExpr`:
             *   - 'leaf' -> `BoundLeaf(TypeRef($fqn, $resolvedArgs))`
             *   - 'and'  -> `BoundIntersection(...$operands)`
             *   - 'or'   -> `BoundUnion(...$operands)`
             *
             * Leaf names route through `resolveNameOnly` for namespace + use-map
             * resolution; leaf args route through `resolveTypeRefList` so
             * F-bounded `Comparable<T>` resolves T against the enclosing
             * type-param stack (marking it as `isTypeParam: true`).
             *
             * @param array{name: string, bound: ?array} $entry
             */
            private function buildBoundExpr(array $entry): ?BoundExpr
            {
                if ($entry['bound'] === null) {
                    return null;
                }
                return $this->buildBoundExprNode($entry['bound']);
            }

            /**
             * Resolve a parsed entry's default expression. The raw `TypeRef`
             * carries the source-level name; `resolveTypeRef` rewrites it via
             * namespace + use-map, marks scalars / type-params, and recurses
             * into nested args (so `B = Box<A>` becomes
             * `TypeRef('App\Box', [TypeRef('A', isTypeParam: true)])`).
             *
             * @param array{name: string, bound: ?array, default: ?TypeRef} $entry
             */
            private function buildDefault(array $entry): ?TypeRef
            {
                if ($entry['default'] === null) {
                    return null;
                }
                return $this->resolveTypeRef($entry['default']);
            }

            /**
             * @param array{kind: string, ...} $node
             */
            private function buildBoundExprNode(array $node): BoundExpr
            {
                if ($node['kind'] === 'leaf') {
                    $fqn = $node['isFq']
                        ? $node['name']
                        : $this->resolveNameOnly($node['name']);
                    $resolvedArgs = $this->resolveTypeRefList($node['args']);
                    return new BoundLeaf(new TypeRef($fqn, $resolvedArgs));
                }
                $operands = array_map(
                    fn (array $op): BoundExpr => $this->buildBoundExprNode($op),
                    $node['operands'],
                );
                if ($node['kind'] === 'and') {
                    return new BoundIntersection(...$operands);
                }
                return new BoundUnion(...$operands);
            }

            public function leaveNode(Node $node): null
            {
                // Variance position check fires once per class definition,
                // AFTER the body has been fully resolved -- so any Name nodes
                // inside the body that carry nested ATTR_GENERIC_ARGS are
                // visible to the validator. Rejects covariant T in input
                // position, contravariant T in output, either in
                // bound/default/property/constructor positions, and
                // F-bounded variance (`+T : Box<T>`).
                if ($node instanceof ClassLike && $node->name !== null) {
                    $params = $node->getAttribute(XphpSourceParser::ATTR_GENERIC_PARAMS);
                    if (is_array($params) && $params !== []) {
                        VariancePositionValidator::assertPositions($node, $params);
                    }
                }
                // @infection-ignore-all -- the instanceof chain mirrors enterNode's push;
                // restructuring `||` as `&&` produces a leaveNode that no longer pops the
                // stack for any node, but the test suite's AST shapes never re-use the
                // same parser instance for multiple parses, so the stale stack would only
                // matter across a series of parses we don't exercise.
                if ($node instanceof ClassLike
                    || $node instanceof Node\Stmt\ClassMethod
                    || $node instanceof Node\Stmt\Function_
                    || $node instanceof Node\Expr\Closure
                    || $node instanceof Node\Expr\ArrowFunction
                ) {
                    array_pop($this->typeParamStack);
                }
                return null;
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

                if ($this->isEnclosingTypeParam($name)) {
                    return new TypeRef($name, $resolvedArgs, isTypeParam: true);
                }

                // @infection-ignore-all — scalar-type tokens already arrive lowercased from PHP grammar.
                $lower = strtolower($name);
                if (in_array($lower, XphpSourceParser::SCALAR_TYPES, true)) {
                    return new TypeRef($lower, $resolvedArgs, isScalar: true);
                }

                return new TypeRef($this->ctx->resolveAgainstContext($name), $resolvedArgs);
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
        });

        $traverser->traverse($ast);
    }
}
