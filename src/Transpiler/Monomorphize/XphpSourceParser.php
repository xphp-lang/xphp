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
 *
 * The bound dict shape is polymorphic by `kind` (declared more precisely in
 * `parseTypeParamList`'s docblock). We can't encode that polymorphism in a
 * recursive type alias (PHPStan rejects recursive aliases as circular), so
 * the `operands` recursion bottoms out at `array<string, mixed>` and the
 * shape-narrowing happens dynamically at access sites.
 *
 * @phpstan-type BoundDict array{kind: 'leaf', name: string, isFq: bool, args: list<TypeRef>}|array{kind: 'and'|'or', operands: list<array<string, mixed>>}
 */
final class XphpSourceParser
{
    public const ATTR_GENERIC_PARAMS = 'xphp:genericParams';
    public const ATTR_GENERIC_ARGS = 'xphp:genericArgs';
    public const ATTR_TEMPLATE_FQN = 'xphp:templateFqn';

    // Resolved FQN for a bare, non-generic class/interface Name in a class-name
    // position (extends/implements/new/catch/instanceof/static-access/type-hint).
    // Recorded at parse time WITHOUT mutating the node, so user-file output stays
    // byte-identical; the Specializer reads it to fully-qualify the name only on
    // a relocated specialized clone (where the source's `use` imports no longer
    // apply and a bare name would otherwise resolve into XPHP\Generated\...).
    public const ATTR_RESOLVED_FQN = 'xphp:resolvedFqn';

    // Method-scoped generics (one type-param set per method, distinct from any class-level set).
    public const ATTR_METHOD_GENERIC_PARAMS = 'xphp:methodGenericParams';
    public const ATTR_METHOD_GENERIC_ARGS = 'xphp:methodGenericArgs';

    // A parsed closure signature type (`Closure(int $x): bool`) attached to the
    // surviving `\Closure` type-hint Name after the `(…)[: ret]` span is erased.
    // Carries a ClosureSignature; read by the compile-time conformance validator.
    public const ATTR_CLOSURE_SIG = 'xphp:closureSig';

    // A bare, single-segment, non-imported class-name used inside a generic context
    // (a template or generic method/function/closure) that is NOT a declared type
    // parameter. Carries the resolved FQN. The undeclared-type-parameter validator
    // flags it when the FQN resolves to no declared type — catching `Foo<Z>` whose
    // member uses an undeclared `T`. Imported / fully-qualified names are never
    // tagged (the escape hatch). Advisory metadata only — not emitted.
    public const ATTR_SUSPECT_UNDECLARED_TYPE = 'xphp:suspectUndeclaredType';

    /**
     * The reserved PHP type keywords — names PHP forbids as class names. A bare name in this list is
     * unambiguously a builtin, so every site that asks "is this name a builtin keyword or a class?"
     * (type-argument / signature / default / bound resolution, and the FQN-rewrite tagger) matches here to
     * leave it unqualified instead of namespace-qualifying it as a class reference.
     *
     * The gettype-style aliases `integer`/`boolean`/`double` are deliberately NOT listed: they are legal
     * class names (`class Double {}`) and are not PHP type keywords (the float keyword is `float`), so a
     * `Double` used anywhere as a type must resolve to the class, never be mistaken for a scalar.
     */
    public const SCALAR_TYPES = [
        'int', 'string', 'bool', 'float',
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
        [$classMarkers, $nameMarkers, $methodMarkers, $cleanedSource, $byteOffsetMap, $closureMarkers] = $this->scanAndStrip($source);

        $ast = $this->parser->parse($cleanedSource);
        if ($ast === null) {
            throw new RuntimeException('Parser returned null AST.');
        }
        /** @var list<Node\Stmt> $ast — nikic's parse() returns array<Stmt>; runtime keys are always 0..N-1. */

        $this->resolveAndAttach($ast, $classMarkers, $nameMarkers, $methodMarkers, $closureMarkers, $byteOffsetMap);

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
        [$classMarkers, $nameMarkers, $methodMarkers, $cleanedSource, $byteOffsetMap, $closureMarkers] = $this->scanAndStrip($source);

        $errorHandler = new \PhpParser\ErrorHandler\Collecting();
        $ast = $this->parser->parse($cleanedSource, $errorHandler);
        if ($ast === null) {
            return null;
        }
        /** @var list<Node\Stmt> $ast — nikic's parse() returns array<Stmt>; runtime keys are always 0..N-1. */

        $this->resolveAndAttach($ast, $classMarkers, $nameMarkers, $methodMarkers, $closureMarkers, $byteOffsetMap);

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
     * @return array{0: list<array{line:int, name:string, kind:string, bytePosition:int, params:list<array{name:string, bound:?BoundDict, default:?TypeRef, variance:Variance}>}>, 1: list<array{line:int, anchorLine:int, name:string, kind:string, bytePosition:int, args:list<TypeRef>}>, 2: list<array{line:int, name:string, kind:string, bytePosition:int, params:list<array{name:string, bound:?BoundDict, default:?TypeRef, variance:Variance}>}>, 3: string, 4: ByteOffsetMap, 5: list<array{bytePosition:int, signature:ClosureSignature}>}
     */
    private function scanAndStrip(string $source): array
    {
        $rawTokens = PhpToken::tokenize($source);
        /** @var list<PhpToken> $rawTokens — PhpToken::tokenize() returns array<PhpToken>; keys are always 0..N-1. */
        $tokens = self::splitMergedAngleTokens($rawTokens);
        $n = count($tokens);

        $classMarkers = [];
        $nameMarkers = [];
        $methodMarkers = [];
        /** @var list<array{bytePosition:int, signature:ClosureSignature}> $closureMarkers */
        $closureMarkers = [];
        /** @var list<array{int, int, string}> $replacements [byte offset, original length, replacement text] */
        $replacements = [];

        $i = 0;
        while ($i < $n) {
            $tok = $tokens[$i];

            // Anonymous closure: `function<T>(...){}` / `fn<T>(...)`.
            // Recognized by T_FUNCTION/T_FN followed immediately by `<` (no
            // T_STRING name). `static`-prefixed shapes are consumed by the
            // T_STATIC arm below before the loop ever reaches the keyword.
            if ($tok->id === T_FUNCTION || $tok->id === T_FN) {
                $isArrow = $tok->id === T_FN;
                // An attributed closure's node starts at its first `#[`, not at
                // the keyword — anchor the (byte-matched) marker there.
                $anchorIdx = self::anchorPastAttributeGroups($tokens, $i);
                $anchorByte = $tokens[$anchorIdx]->pos;
                $anchorLine = $tokens[$anchorIdx]->line;
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
                        $replacements[] = [$startByte, $length, self::blank(substr($source, $startByte, $length))];
                        $i = $endIdx + 1;
                        continue;
                    }
                }
                // Not a closure with generic params -- fall through to the
                // named-function path (T_FUNCTION) or skip the token (T_FN).
            }

            // `static function<T>(...)` / `static fn<T>(...)` -- the leading
            // T_STATIC must be recognized so the anchor covers it (the node
            // starts at `static`, or at a preceding attribute group). A static
            // ARROW takes the ordinary arrow specialization path — it cannot
            // bind `$this` by construction, which is the only thing the
            // dispatcher rewrite cannot carry; static CLOSURES stay gated
            // (kind `staticClosure` hard-fails at the call-site rewrite).
            if ($tok->id === T_STATIC) {
                $j = self::skipWs($tokens, $i + 1);
                if ($j < $n && ($tokens[$j]->id === T_FUNCTION || $tokens[$j]->id === T_FN)) {
                    $isArrow = $tokens[$j]->id === T_FN;
                    $k = self::skipWs($tokens, $j + 1);
                    if ($k < $n && $tokens[$k]->text === '<') {
                        $parsed = self::parseTypeParamList(
                            $tokens,
                            $k,
                            allowDefaults: $isArrow,
                            allowVariance: false,
                        );
                        if ($parsed !== null) {
                            [$paramEntries, $endIdx] = $parsed;
                            $anchorIdx = self::anchorPastAttributeGroups($tokens, $i);
                            $methodMarkers[] = [
                                'line' => $tokens[$anchorIdx]->line,
                                'name' => '',
                                'kind' => $isArrow ? 'arrow' : 'staticClosure',
                                'bytePosition' => $tokens[$anchorIdx]->pos,
                                'params' => $paramEntries,
                            ];
                            $startByte = $tokens[$k]->pos;
                            $endByte = $tokens[$endIdx]->pos + strlen($tokens[$endIdx]->text);
                            $length = $endByte - $startByte;
                            $replacements[] = [$startByte, $length, self::blank(substr($source, $startByte, $length))];
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
                            $replacements[] = [$startByte, $length, self::blank(substr($source, $startByte, $length))];
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
                            $replacements[] = [$startByte, $length, self::blank(substr($source, $startByte, $length))];
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
                        $replacements[] = [$startByte, $length, self::blank(substr($source, $startByte, $length))];
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
                        $replacements[] = [$startByte, $length, self::blank(substr($source, $startByte, $length))];
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
                            $replacements[] = [$startByte, $length, self::blank(substr($source, $startByte, $length))];
                            $i = $endIdx + 1;
                            continue;
                        }
                    }
                }

                // Closure signature type `Closure(params): return` in a type slot.
                // Fires for any `Name(` not in a member-access / `new` context;
                // `tryParseClosureSignature`'s position gate distinguishes a genuine
                // type slot from an expression-context call (which falls through
                // untouched), and throws the two structural errors. A signature-shaped
                // production on a non-`Closure` name is the only-`Closure` compile error.
                // @infection-ignore-all — the `(`/cast opener test is a fast-path guard:
                // findClosureSigEnd re-validates the opener and returns null for anything
                // that is not a `(` or a cast token, so negating this sub-expression only
                // adds calls that bail to null — observably equivalent.
                if ($j < $n && ($tokens[$j]->text === '(' || self::isCastToken($tokens[$j]))
                    && !self::isMemberAccessContext($tokens, $i)
                    && !self::isPrecededByNew($tokens, $i)
                ) {
                    $sig = self::tryParseClosureSignature($tokens, $i, $j, $source);
                    if ($sig !== null) {
                        [$signature, $endIdx, $spanStart, $spanLen, $replacement] = $sig;
                        $closureMarkers[] = [
                            'bytePosition' => $tok->pos,
                            'signature' => $signature,
                        ];
                        $replacements[] = [$spanStart, $spanLen, $replacement];
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
                        // `array` is shorter than most sugar spans, so byte length
                        // can't be preserved here — but the span's newlines must be
                        // (line-keyed markers after it depend on the line count).
                        // ByteOffsetMap::fromReplacements absorbs the length delta.
                        $replacements[] = [
                            $startByte,
                            $endByte - $startByte,
                            'array' . self::newlinesOf(substr($source, $startByte, $endByte - $startByte)),
                        ];
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

        return [$classMarkers, $nameMarkers, $methodMarkers, $cleaned, $byteOffsetMap, $closureMarkers];
    }

    /**
     * Recognize + parse a closure signature type `Closure(params): return` that
     * begins at the `Closure`/`\Closure` name token index `$i`, where `$j` is the
     * index of the `(` immediately following the name (past whitespace).
     *
     * Returns `[ClosureSignature, spanEndIdx, spanStartByte, spanLen, replacement]`
     * when the position is a genuine type slot, or `null` when it is an ordinary
     * expression (e.g. a call to a user function named `Closure`) that must be left
     * untouched. Throws for the two structural errors (only-`Closure`, variadic-last).
     *
     * @infection-ignore-all — the accept/reject behavior (type-slot vs. call, the two
     * structural throws) and the newline-preserving erasure math are pinned by
     * behavioral tests; the sole residual mutant is the defensive
     * `findClosureSigEnd() === null` guard, unreachable once the pre-filter has matched
     * a balanced `(…)` at a genuine type slot.
     *
     * @param list<PhpToken> $tokens
     * @return array{0: ClosureSignature, 1: int, 2: int, 3: int, 4: string}|null
     */
    private static function tryParseClosureSignature(array $tokens, int $i, int $j, string $source): ?array
    {
        $n = count($tokens);

        // Cheap pre-filter: a signature's first inner token is type-ish, never a
        // `$var` or literal (which a real call `Closure($x)` / `Closure(5)` has).
        // A cast token (`Closure(int)`) is itself the whole one-scalar param list.
        if (!self::isCastToken($tokens[$j])) {
            $firstInner = self::skipWs($tokens, $j + 1);
            if ($firstInner >= $n) {
                return null;
            }
            $ft = $tokens[$firstInner];
            $firstOk = self::isSigTypeToken($ft)
                || $ft->id === T_ELLIPSIS
                || in_array($ft->text, ['?', '(', ')', '&'], true);
            if (!$firstOk) {
                return null;
            }
        }

        // Balanced end of `( … )` plus an optional `: return`.
        $spanEnd = self::findClosureSigEnd($tokens, $j);
        if ($spanEnd === null) {
            return null;
        }

        // Position gate: a type slot is a param/property (a `$var` follows the whole
        // signature) or a return type (the name follows the `:` of a `function`/`fn`
        // declaration header — see isClosureReturnSlot). Anything else is
        // expression-context `Closure(` — leave it.
        $afterSpan = self::skipWs($tokens, $spanEnd + 1);
        $isParamOrProp = $afterSpan < $n && $tokens[$afterSpan]->id === T_VARIABLE;
        $isReturn = self::isClosureReturnSlot($tokens, $i);
        if (!$isParamOrProp && !$isReturn) {
            return null;
        }

        $name = ltrim($tokens[$i]->text, '\\');
        if ($name !== 'Closure') {
            throw new RuntimeException(sprintf(
                'Only "Closure" may carry a call signature, "%s" may not',
                $name,
            ));
        }

        $nullable = self::isNullablePrefixed($tokens, $i);
        $signature = self::buildClosureSignature($tokens, $j, $source, $nullable);

        // Erase to `\Closure`, preserving both the newline count (line-keyed
        // markers depend on it) and the total byte length (byte-keyed markers —
        // e.g. anonymous-closure generics — depend on positions after the span not
        // shifting). `\Closure` is 8 bytes; a bare `Closure` name is only 7, so the
        // extra `\` reclaims one blank byte from the tail (which always begins with
        // `(`, never a newline). A fully-qualified `\Closure` name is already 8.
        $spanStart = $tokens[$i]->pos;
        $nameLen = strlen($tokens[$i]->text);
        $spanEndByte = $tokens[$spanEnd]->pos + strlen($tokens[$spanEnd]->text);
        $drop = strlen('\\Closure') - $nameLen;
        $tail = substr($source, $spanStart + $nameLen + $drop, $spanEndByte - ($spanStart + $nameLen + $drop));
        $replacement = '\\Closure' . self::blank($tail);

        return [$signature, $spanEnd, $spanStart, $spanEndByte - $spanStart, $replacement];
    }

    /**
     * The scalar-cast token ids (`(int)`, `(bool)`, `(float)`, `(string)`,
     * `(array)`, `(object)`, `(unset)`). A bare single-scalar signature parameter
     * — `Closure(int)` — is lexed by PHP as one cast token rather than
     * `(` `int` `)`, so the scanner must accept a cast token where it expects the
     * parameter-list parens (the same collision the engine RFC handles).
     *
     * @return array<int, string> token-id → canonical scalar name
     */
    private static function castTokenScalars(): array
    {
        return [
            T_INT_CAST => 'int',
            T_BOOL_CAST => 'bool',
            T_DOUBLE_CAST => 'float',
            T_STRING_CAST => 'string',
            T_ARRAY_CAST => 'array',
            T_OBJECT_CAST => 'object',
            T_UNSET_CAST => 'void',
        ];
    }

    private static function isCastToken(PhpToken $tok): bool
    {
        return array_key_exists($tok->id, self::castTokenScalars());
    }

    /**
     * True for a token that can lead a signature type leaf: an ordinary name
     * ({@see isNameToken}) plus the reserved-word type keywords `array`,
     * `callable`, and `static`, which PHP lexes as their own tokens
     * (T_ARRAY / T_CALLABLE / T_STATIC) rather than T_STRING — so `isNameToken`
     * alone would miss them and a signature like `Closure(array $a): callable`
     * would fail to erase.
     */
    private static function isSigTypeToken(PhpToken $tok): bool
    {
        return self::isNameToken($tok)
            || $tok->id === T_ARRAY
            || $tok->id === T_CALLABLE
            || $tok->id === T_STATIC;
    }

    /**
     * Index of the last token of a closure signature whose parameter list opens at
     * `$openIdx` (a `(` OR a single cast token like `(int)`): the matching `)` (or
     * the cast token itself), extended over an optional `: <returnType>`. `null` if
     * the parens are unbalanced.
     *
     * @param list<PhpToken> $tokens
     */
    private static function findClosureSigEnd(array $tokens, int $openIdx): ?int
    {
        $n = count($tokens);
        // @infection-ignore-all GreaterThanOrEqualTo — every caller derives $openIdx
        // from an existing token's lookahead, so $openIdx === $n is unreachable;
        // the guard is defensive.
        if ($openIdx >= $n) {
            return null;
        }
        if (self::isCastToken($tokens[$openIdx])) {
            $closeIdx = $openIdx;
        } elseif ($tokens[$openIdx]->text === '(') {
            $depth = 0;
            $closeIdx = null;
            for ($i = $openIdx; $i < $n; $i++) {
                $t = $tokens[$i]->text;
                if ($t === '(') {
                    $depth++;
                } elseif ($t === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $closeIdx = $i;
                        break;
                    }
                }
            }
            if ($closeIdx === null) {
                // @infection-ignore-all ReturnRemoval — removing this early return
                // sends `null + 1` into the skipWs below; the token near the file
                // start it lands on is never a `:`, so the function still returns
                // the null $closeIdx — equivalent, just accidental.
                return null;
            }
        } else {
            return null;
        }
        $k = self::skipWs($tokens, $closeIdx + 1);
        if ($k < $n && $tokens[$k]->text === ':') {
            $retStart = self::skipWs($tokens, $k + 1);
            return self::scanTypeExprEnd($tokens, $retStart);
        }
        return $closeIdx;
    }

    /**
     * Index of the last token of a type expression starting at `$idx` — a leaf
     * (scalar / class / `Name<…>` generic / nested `Closure(…)` / nullable /
     * parenthesised DNF group) plus any `|` / `&` union-or-intersection
     * continuation. Stops before a top-level `$var` / `{` / `;` / `,` / `)` /
     * `=`. `null` if nothing type-shaped is there.
     *
     * A `(` is a DNF group only where a LEAF may start (scan start, or right
     * after a `|` / `&` continuation), and only when its interior is itself a
     * valid type expression ending exactly at the matching `)` — an
     * expression-context paren (`Closure(int) : ($flag ? …)`) fails that
     * interior check and terminates the scan as before.
     *
     * @param list<PhpToken> $tokens
     */
    private static function scanTypeExprEnd(array $tokens, int $idx): ?int
    {
        $n = count($tokens);
        $last = null;
        $i = $idx;
        $expectLeaf = true;
        while ($i < $n) {
            $t = $tokens[$i];
            if ($t->id === T_WHITESPACE || $t->id === T_COMMENT || $t->id === T_DOC_COMMENT) {
                $i++;
                continue;
            }
            if ($t->text === '?') {
                $last = $i;
                $i++;
                continue;
            }
            if ($t->text === '(' && $expectLeaf) {
                $close = self::scanGroupEnd($tokens, $i);
                if ($close === null) {
                    break;
                }
                $last = $close;
                $i = $close + 1;
                $expectLeaf = false;
                continue;
            }
            if (self::isSigTypeToken($t)) {
                $last = $i;
                $expectLeaf = false;
                $la = self::skipWs($tokens, $i + 1);
                if ($la < $n && ($tokens[$la]->text === '(' || self::isCastToken($tokens[$la])) && ltrim($t->text, '\\') === 'Closure') {
                    $inner = self::findClosureSigEnd($tokens, $la);
                    if ($inner === null) {
                        return null;
                    }
                    $last = $inner;
                    // @infection-ignore-all DecrementInteger — re-entering the walk AT
                    // the consumed signature's last token (`+ 0`) only revisits a token
                    // that cannot extend or shrink the span (it is never a name with a
                    // consumable tail), so `$last` is unchanged. Re-entering BEFORE it
                    // (`- 1`) is NOT equivalent (it can re-consume the nested signature
                    // or overwrite `$last`) and is pinned by the nested-no-return test;
                    // skipping forward (`+ 2`) is pinned by the group-with-nested-
                    // signature test.
                    $i = $inner + 1;
                } elseif ($la < $n && $tokens[$la]->text === '<') {
                    $inner = self::findAngleEnd($tokens, $la);
                    if ($inner === null) {
                        return null;
                    }
                    $last = $inner;
                    $i = $inner + 1;
                } else {
                    // `Name[]` array sugar: consume the bracket pair into the
                    // leaf so it erases with the signature (the global `T[]` →
                    // `array` rewrite never sees it). `Name<Args>[]` is NOT
                    // consumed — the generic-sugar combination stays the same
                    // loud pre-existing gap it is outside signatures.
                    $suffix = self::arraySuffixEnd($tokens, $i + 1);
                    if ($suffix !== null) {
                        $last = $suffix;
                        $i = $suffix + 1;
                    } else {
                        $i++;
                    }
                }
                continue;
            }
            if ($t->text === '|' || $t->text === '&') {
                // `&` before a `$var` / `...` / `)` is a by-ref marker (belongs to
                // the parameter, not the type) — stop. `&`/`|` before a Name, `?`,
                // or a `(` group is an intersection / union continuation.
                $nx = self::skipWs($tokens, $i + 1);
                $continues = $nx < $n
                    && (self::isSigTypeToken($tokens[$nx])
                        || $tokens[$nx]->text === '?'
                        || $tokens[$nx]->text === '(');
                if (!$continues) {
                    break;
                }
                $i++;
                $expectLeaf = true;
                continue;
            }
            break;
        }
        return $last;
    }

    /**
     * Index of the LAST `]` of one-or-more chained EMPTY `[ ]` bracket pairs
     * whose first `[` is the first significant token at or after `$idx`
     * (whitespace/comments tolerated), or `null` when the next tokens are not
     * array sugar. Chains (`T[][]`) are consumed whole, matching the global
     * sugar's parseArraySuffix. A non-empty `[expr]` is expression syntax,
     * never type sugar — the `]` check rejects it.
     *
     * @param list<PhpToken> $tokens
     */
    private static function arraySuffixEnd(array $tokens, int $idx): ?int
    {
        $n = count($tokens);
        $end = null;
        while (true) {
            $open = self::skipWs($tokens, $idx);
            if ($open >= $n || $tokens[$open]->text !== '[') {
                return $end;
            }
            $close = self::skipWs($tokens, $open + 1);
            if ($close >= $n || $tokens[$close]->text !== ']') {
                return $end;
            }
            $end = $close;
            $idx = $close + 1;
        }
    }

    /**
     * Index of the matching `)` for a parenthesised DNF group whose `(` sits at
     * `$openIdx`, or `null` when the interior is not a complete type expression
     * ending exactly at that `)`. The interior reuses the full leaf machinery
     * (names, generics via `findAngleEnd`, nested `Closure(…)` recursion, nested
     * groups), so an expression paren (`($flag ? a() : b())`, `($x)`) is rejected
     * and never absorbed into a type span.
     *
     * @param list<PhpToken> $tokens
     */
    private static function scanGroupEnd(array $tokens, int $openIdx): ?int
    {
        $inner = self::scanTypeExprEnd($tokens, self::skipWs($tokens, $openIdx + 1));
        if ($inner === null) {
            // @infection-ignore-all ReturnRemoval — removing the early return sends
            // `null + 1` into skipWs, whose result (a token near the file start) is
            // never the group's `)`, so the ternary below still yields null.
            return null;
        }
        $close = self::skipWs($tokens, $inner + 1);
        return $close < count($tokens) && $tokens[$close]->text === ')' ? $close : null;
    }

    /**
     * Index of the matching `>` for a `<` at `$openIdx` (generic arg clause).
     * Assumes `splitMergedAngleTokens` has already split `>>` etc. `null` if
     * unbalanced.
     *
     * @infection-ignore-all — flat balanced-angle scan; the depth logic is pinned by
     * the nested-generic test (a broken counter leaves a stray `>` that fails to
     * re-parse), and the sole residual mutant is the `$i < $n` loop-bound guard, which
     * is defensive: the caller only passes a `<` that has a matching `>`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function findAngleEnd(array $tokens, int $openIdx): ?int
    {
        $n = count($tokens);
        $depth = 0;
        for ($i = $openIdx; $i < $n; $i++) {
            $t = $tokens[$i]->text;
            if ($t === '<') {
                $depth++;
            } elseif ($t === '>') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * True iff the `Closure` at `$i` sits in a genuine RETURN-TYPE slot: preceded
     * (past an optional nullable `?` and whitespace) by `:` which closes a
     * parameter list (or a closure's `use (…)` clause) headed by `function` / `fn`.
     *
     * `)`-then-`:` alone is NOT enough — a ternary's else (`$a ? b() : …`), a
     * `case expr():` label, and every alt-syntax construct (`if/elseif/while/
     * for/foreach/declare (…):`) produce the same pair; keying on them silently
     * erased expression-context `Closure(…)` calls and hard-threw the
     * only-Closure error on ordinary calls (`$a ? b() : g(FOO)`). The walk now
     * matches the `)` back to its `(` and requires a declaration head, rejecting
     * `C::fn()` / `$o->fn()` member CALLS whose head token is the semi-reserved
     * `fn` / `function` after `::` / `->` / `?->`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function isClosureReturnSlot(array $tokens, int $i): bool
    {
        $p = self::skipWsBack($tokens, $i - 1);
        // @infection-ignore-all GreaterThanOrEqualTo — token 0 (open tag) is never
        // `?`, so testing index 0 cannot change the outcome.
        if ($p >= 0 && $tokens[$p]->text === '?') {
            $p = self::skipWsBack($tokens, $p - 1);
        }
        // @infection-ignore-all LessThan — token 0 (open tag) is never `:`, so
        // testing index 0 cannot change the outcome.
        if ($p < 0 || $tokens[$p]->text !== ':') {
            return false;
        }
        $q = self::skipWsBack($tokens, $p - 1);
        // @infection-ignore-all LessThan — token 0 is always the open tag, never
        // `)`, so testing index 0 cannot change the outcome.
        if ($q < 0 || $tokens[$q]->text !== ')') {
            return false;
        }
        $open = self::matchParenBack($tokens, $q);
        if ($open === null) {
            return false;
        }
        $h = self::skipWsBack($tokens, $open - 1);
        // One `use (…)` layer: `function () use ($a): R` — the `)` before the
        // `:` closes the use clause; hop to the parameter list it follows.
        // (Use clauses don't nest, so one layer suffices.)
        // @infection-ignore-all GreaterThanOrEqualTo — token 0 (open tag) is never
        // T_USE, so testing index 0 cannot change the outcome.
        if ($h >= 0 && $tokens[$h]->id === T_USE) {
            $h = self::skipWsBack($tokens, $h - 1);
            // @infection-ignore-all LessThan LogicalOr — token 0 is never `)`
            // (LessThan); and a reached T_USE always has preceding significant
            // tokens (`use` is never the first token after the open tag), so
            // h >= 0 always holds and the OR arms cannot disagree. The `)` test
            // itself is load-bearing: a STATIC member call of a method named
            // `use` (`C::use($q) : …` — after `->` the name lexes as a
            // contextual T_STRING and never reaches here) arrives with `::`
            // before the T_USE and must be rejected — pinned by the
            // static-use-call provider entry.
            if ($h < 0 || $tokens[$h]->text !== ')') {
                return false;
            }
            $open = self::matchParenBack($tokens, $h);
            if ($open === null) {
                return false;
            }
            $h = self::skipWsBack($tokens, $open - 1);
        }
        // Optional declaration pieces between the head and the `(`, walked
        // backwards: a GENERIC clause (`function<T>(…)`, `function m<T : B>(…)`),
        // then a NAME, then a by-ref `&` (`function &f(…)`, `fn &(…)`).
        // @infection-ignore-all GreaterThanOrEqualTo — token 0 (open tag) is
        // never `>`.
        if ($h >= 0 && $tokens[$h]->text === '>') {
            $angleOpen = self::matchAngleBack($tokens, $h);
            if ($angleOpen === null) {
                return false;
            }
            $h = self::skipWsBack($tokens, $angleOpen - 1);
        }
        // The name may be ANY single token: PHP allows every semi-reserved
        // keyword as a METHOD name (`function list()`, `function default()`),
        // and those lex as their own keyword tokens, not T_STRING — so no
        // name-token test can enumerate them. The head check below is the real
        // discriminator; the name slot just skips one token that is neither
        // the by-ref marker nor the head itself.
        // @infection-ignore-all GreaterThanOrEqualTo IncrementInteger — token 0
        // (open tag) is never a name; and starting the back-skip one index
        // earlier only matters when the skipped token is significant, which for
        // the name slot is only the by-ref `&` — both routes then land on the
        // same T_FUNCTION and accept identically.
        if ($h >= 0 && $tokens[$h]->text !== '&'
            && $tokens[$h]->id !== T_FUNCTION && $tokens[$h]->id !== T_FN
        ) {
            $h = self::skipWsBack($tokens, $h - 1);
        }
        // @infection-ignore-all GreaterThanOrEqualTo — token 0 is never `&`.
        if ($h >= 0 && $tokens[$h]->text === '&') {
            $h = self::skipWsBack($tokens, $h - 1);
        }
        // @infection-ignore-all LessThan — token 0 is never T_FUNCTION/T_FN, so
        // testing index 0 cannot flip the head check.
        if ($h < 0 || ($tokens[$h]->id !== T_FUNCTION && $tokens[$h]->id !== T_FN)) {
            return false;
        }
        // `C::fn(…)` / `$o->function(…)` are member CALLS — `fn`/`function` are
        // semi-reserved and lex as T_FN/T_FUNCTION even after `::`.
        $before = self::skipWsBack($tokens, $h - 1);
        // @infection-ignore-all LessThan — token 0 (open tag) is never `::`/`->`/`?->`,
        // so testing index 0 cannot change the verdict.
        return $before < 0 || !in_array(
            $tokens[$before]->id,
            [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR],
            true,
        );
    }

    /**
     * Index of the `<` matching the `>` at `$closeIdx`, scanning backwards, or
     * `null` if unbalanced. Merged `>>` tokens are already split before any
     * signature scanning, so whole-token text compares suffice.
     *
     * @param list<PhpToken> $tokens
     */
    private static function matchAngleBack(array $tokens, int $closeIdx): ?int
    {
        $depth = 0;
        // @infection-ignore-all GreaterThanOrEqualTo — token 0 is the open tag, never
        // `<` or `>`, so excluding it from the walk cannot change the result.
        for ($i = $closeIdx; $i >= 0; $i--) {
            $t = $tokens[$i]->text;
            if ($t === '>') {
                $depth++;
            } elseif ($t === '<') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * Index of the `(` matching the `)` at `$closeIdx`, scanning backwards, or
     * `null` if unbalanced. Compares whole-token text, so parens INSIDE a
     * string/comment/cast token never perturb the depth.
     *
     * @param list<PhpToken> $tokens
     */
    private static function matchParenBack(array $tokens, int $closeIdx): ?int
    {
        $depth = 0;
        // @infection-ignore-all GreaterThanOrEqualTo — token 0 is the open tag, never
        // `(` or `)`, so excluding it from the walk cannot change the result.
        for ($i = $closeIdx; $i >= 0; $i--) {
            $t = $tokens[$i]->text;
            if ($t === ')') {
                $depth++;
            } elseif ($t === '(') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * True iff a `?` (nullable) immediately precedes the `Closure` at `$i`.
     *
     * @infection-ignore-all — flat backward token walk; the nullable/non-nullable
     * behavior is pinned by the `?Closure` tests, and the residual `$p >= 0` guard is
     * defensive (index 0 is always T_OPEN_TAG, so a `?` can never sit at index 0).
     *
     * @param list<PhpToken> $tokens
     */
    private static function isNullablePrefixed(array $tokens, int $i): bool
    {
        $p = self::skipWsBack($tokens, $i - 1);
        return $p >= 0 && $tokens[$p]->text === '?';
    }

    /**
     * Skip whitespace/comments walking backwards from `$i`; returns the index of
     * the first significant token at or before `$i`, or -1.
     *
     * @infection-ignore-all GreaterThanOrEqualTo — `>= 0` vs `> 0` differs only in
     * whether token 0 is tested for whitespace; token 0 is always the open tag
     * (never T_WHITESPACE), so both stop at 0 with the same result.
     *
     * @param list<PhpToken> $tokens
     */
    private static function skipWsBack(array $tokens, int $i): int
    {
        while ($i >= 0
            && ($tokens[$i]->id === T_WHITESPACE || $tokens[$i]->id === T_COMMENT || $tokens[$i]->id === T_DOC_COMMENT)) {
            $i--;
        }
        return $i;
    }

    /**
     * The true start of an anonymous closure/arrow node whose keyword sits at
     * `$keywordIdx`: php-parser starts the node at its FIRST token — the first
     * attribute group when the closure is attributed — while the scanner sits
     * on the `function` / `fn` / `static` keyword. Anonymous markers are
     * matched byte-exact against the node start, so walk back over any number
     * of complete `#[ … ]` groups (and the whitespace/comments between them)
     * to the token the node actually starts at. Anything that is not a
     * complete attribute group ends the walk.
     *
     * @param list<PhpToken> $tokens
     */
    private static function anchorPastAttributeGroups(array $tokens, int $keywordIdx): int
    {
        $anchor = $keywordIdx;
        $i = self::skipWsBack($tokens, $keywordIdx - 1);
        while ($i >= 0 && $tokens[$i]->text === ']') {
            $open = self::matchAttributeGroupBack($tokens, $i);
            if ($open === null) {
                break;
            }
            $anchor = $open;
            $i = self::skipWsBack($tokens, $open - 1);
        }
        return $anchor;
    }

    /**
     * Match a `]` at `$closeIdx` back to the `#[` (T_ATTRIBUTE) that opens its
     * attribute group, tracking square-bracket balance so attribute arguments
     * containing array literals (`#[A([1, 2])]`) don't derail the walk.
     * Returns null when the balance lands on a plain `[` instead — the `]`
     * closed ordinary array syntax, not an attribute group.
     *
     * @param list<PhpToken> $tokens
     */
    private static function matchAttributeGroupBack(array $tokens, int $closeIdx): ?int
    {
        $depth = 0;
        // @infection-ignore-all GreaterThanOrEqualTo — token 0 is the open tag, never
        // `]`, `[`, or `#[`, so excluding it from the walk cannot change the result.
        for ($i = $closeIdx; $i >= 0; $i--) {
            $t = $tokens[$i];
            if ($t->text === ']') {
                $depth++;
            } elseif ($t->id === T_ATTRIBUTE) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            } elseif ($t->text === '[') {
                $depth--;
                if ($depth === 0) {
                    return null;
                }
            }
        }
        return null;
    }

    /**
     * Build the structured `ClosureSignature` for a `(` at `$openIdx`. Throws the
     * variadic-not-last structural error. Nested `Closure(…)` leaves recurse.
     *
     * @infection-ignore-all — structural correctness (param arity, the cast-token
     * single-scalar param, absent-vs-present return, the variadic-not-last throw,
     * nested recursion) is pinned by behavioral accept/reject tests; the residual
     * mutants are all `$i < $n` loop/lookahead guards and whitespace-skip offsets,
     * equivalent because the param loop is terminated by the closing `)` the caller
     * guarantees and the tightly-packed / spaced fixtures both assert the same shape.
     *
     * @param list<PhpToken> $tokens
     */
    private static function buildClosureSignature(array $tokens, int $openIdx, string $source, bool $nullable): ClosureSignature
    {
        $n = count($tokens);

        // `Closure(int)` — the whole one-scalar param list is a single cast token.
        if (self::isCastToken($tokens[$openIdx])) {
            $scalar = self::castTokenScalars()[$tokens[$openIdx]->id];
            // No isScalar flag here: resolveTypeRef re-derives it from SCALAR_TYPES
            // during resolution, so setting it at scan time would be dead.
            $params = [new ClosureSignatureParam(new SigTypeRef(new TypeRef($scalar)))];
            $return = null;
            $k = self::skipWs($tokens, $openIdx + 1);
            if ($k < $n && $tokens[$k]->text === ':') {
                [$return] = self::parseSigType($tokens, self::skipWs($tokens, $k + 1), $source);
            }
            return new ClosureSignature($params, $return, $nullable);
        }

        $params = [];
        $i = self::skipWs($tokens, $openIdx + 1);
        $sawVariadic = false;
        while ($i < $n && $tokens[$i]->text !== ')') {
            if ($sawVariadic) {
                throw new RuntimeException('Only the last parameter of a Closure signature can be variadic');
            }
            [$param, $i] = self::parseSigParam($tokens, $i, $source);
            $params[] = $param;
            $sawVariadic = $param->variadic;
            $i = self::skipWs($tokens, $i);
            if ($i < $n && $tokens[$i]->text === ',') {
                $i = self::skipWs($tokens, $i + 1);
            }
        }
        $return = null;
        $k = self::skipWs($tokens, min($i, $n - 1) + 1);
        if ($k < $n && $tokens[$k]->text === ':') {
            $retStart = self::skipWs($tokens, $k + 1);
            [$return] = self::parseSigType($tokens, $retStart, $source);
        }
        return new ClosureSignature($params, $return, $nullable);
    }

    /**
     * Parse one signature parameter `type [&] [...] [$name]` beginning at `$i`.
     *
     * @infection-ignore-all — flat token walk reading the optional `&`, `...` and
     * `$name` markers in order; the by-ref / variadic capture is pinned by the by-ref,
     * variadic, and by-ref-variadic tests, and the residual mutants are `$i < $n`
     * lookahead guards and whitespace-skip offsets equivalent under the same inputs.
     *
     * @param list<PhpToken> $tokens
     * @return array{0: ClosureSignatureParam, 1: int}
     */
    private static function parseSigParam(array $tokens, int $i, string $source): array
    {
        $n = count($tokens);
        [$type, $i] = self::parseSigType($tokens, $i, $source);
        $i = self::skipWs($tokens, $i);
        $byRef = false;
        $variadic = false;
        if ($i < $n && $tokens[$i]->text === '&') {
            $byRef = true;
            $i = self::skipWs($tokens, $i + 1);
        }
        if ($i < $n && $tokens[$i]->id === T_ELLIPSIS) {
            $variadic = true;
            $i = self::skipWs($tokens, $i + 1);
        }
        if ($i < $n && $tokens[$i]->id === T_VARIABLE) {
            $i++;
        }
        return [new ClosureSignatureParam($type, $byRef, $variadic), $i];
    }

    /**
     * Parse one signature leaf type beginning at `$i` → `[SigType, nextIdx]`.
     * A nested `Closure(…)` becomes a `SigClosure`; a plain scalar / class /
     * type-parameter / `Name<…>` leaf becomes a `SigTypeRef`; a flat `?A` / `A|B` /
     * `A&B` is structured into a `SigUnion` / `SigIntersection` (the member-splitting
     * lives in {@see parseFlatCompound}); a shape the flat splitter does not own (a
     * DNF group, a mixed `|`/`&`, a scalar in an intersection) falls back to a
     * gradual `SigRaw`. Members are left unresolved — the resolver walks the tree.
     *
     * @infection-ignore-all — leaf-kind DISPATCH (nested `SigClosure`, nullable,
     * union/intersection, plain `SigTypeRef`) is pinned behaviorally by the parser's
     * accept/reject fixtures; the member-splitting logic it delegates to lives in
     * {@see parseFlatCompound} / {@see parseMemberLeaf} (fully mutation-covered), and
     * the residual mutants here are `$i < $n` lookahead guards and whitespace-skip
     * offsets, equivalent for the balanced, type-shaped spans the caller passes.
     *
     * @param list<PhpToken> $tokens
     * @return array{0: SigType, 1: int}
     */
    private static function parseSigType(array $tokens, int $i, string $source): array
    {
        $n = count($tokens);
        if ($i < $n && self::isNameToken($tokens[$i]) && ltrim($tokens[$i]->text, '\\') === 'Closure') {
            $la = self::skipWs($tokens, $i + 1);
            if ($la < $n && ($tokens[$la]->text === '(' || self::isCastToken($tokens[$la]))) {
                $end = self::findClosureSigEnd($tokens, $la);
                if ($end !== null) {
                    return [new SigClosure(self::buildClosureSignature($tokens, $la, $source, false)), $end + 1];
                }
            }
        }

        // A leading `?` marks a nullable leaf — keep the whole leaf raw for now.
        $nullableLeaf = $i < $n && $tokens[$i]->text === '?';
        $start = $i;
        if ($nullableLeaf) {
            $i = self::skipWs($tokens, $i + 1);
        }

        $parsed = self::parseTypeArg($tokens, $i);
        if ($parsed === null && $i < $n && self::isSigTypeToken($tokens[$i])) {
            // `array` / `callable` / `static` — reserved-word type keywords that
            // parseTypeArg (T_STRING / T_NAME only) does not recognize. Build the
            // leaf directly; resolveTypeRef treats them as built-ins via SCALAR_TYPES.
            $parsed = [new TypeRef(strtolower($tokens[$i]->text)), $i + 1];
        }
        if ($parsed === null) {
            $end = self::scanTypeExprEnd($tokens, $start);
            $end = $end ?? $start;
            return [new SigRaw(self::sliceTokens($tokens, $source, $start, $end)), $end + 1];
        }
        [$typeRef, $afterLeaf] = $parsed;

        // `Name[]` array sugar lowers to `array` — the same lowering the global
        // rewrite applies outside signatures; as a gradual leaf it can never
        // false-reject. `Name<Args>[]` is excluded (the pre-existing loud gap).
        $suffix = self::arraySuffixEnd($tokens, $afterLeaf);
        if ($suffix !== null && !$typeRef->isGeneric()) {
            $typeRef = new TypeRef('array');
            $afterLeaf = $suffix + 1;
        }

        $peek = self::skipWs($tokens, $afterLeaf);
        $hasUnion = $peek < $n && $tokens[$peek]->text === '|';
        $hasIntersection = $peek < $n && $tokens[$peek]->text === '&'
            && self::sigAmpersandIsIntersection($tokens, $peek);

        // A leading `?` is `A|null`. `?A|B` / `?A&B` are invalid PHP; keep raw.
        if ($nullableLeaf) {
            if ($hasUnion || $hasIntersection) {
                return self::rawSigLeaf($tokens, $source, $start, $afterLeaf);
            }
            return [new SigUnion([new SigTypeRef($typeRef), new SigTypeRef(new TypeRef('null'))]), $afterLeaf];
        }

        // A flat union / intersection continuation → structure it. A DNF paren, a
        // mixed `|`/`&` at top level, or a scalar in an intersection falls back to a
        // gradual SigRaw (structured DNF on the token side is a tracked follow-up).
        if ($hasUnion || $hasIntersection) {
            $compound = self::parseFlatCompound($tokens, $typeRef, $afterLeaf, $hasUnion ? '|' : '&');
            return $compound ?? self::rawSigLeaf($tokens, $source, $start, $afterLeaf);
        }

        return [new SigTypeRef($typeRef), $afterLeaf];
    }

    /**
     * Collect the remaining members of a FLAT union / intersection (the first is
     * already parsed) into a {@see SigUnion} / {@see SigIntersection}. Returns null
     * to fall back to a gradual {@see SigRaw} for a case the flat splitter does not
     * own: a DNF group `(…)`, a mixed `|`/`&` at top level, an intersection member
     * that is a scalar (invalid PHP), or a member that isn't a type leaf.
     *
     * @param list<PhpToken> $tokens
     * @param string $sep '|' or '&'
     * @return array{0: SigType, 1: int}|null
     */
    private static function parseFlatCompound(array $tokens, TypeRef $first, int $afterFirst, string $sep): ?array
    {
        $n = count($tokens);
        $members = [$first];
        $lastEnd = $afterFirst;
        $i = self::skipWs($tokens, $afterFirst);
        // @infection-ignore-all LessThan — `$i < $n` is a defensive bound; a well-formed
        // signature always has a following `)` / `{`, so `$i` never reaches `$n` here.
        while ($i < $n && $tokens[$i]->text === $sep) {
            $j = self::skipWs($tokens, $i + 1);
            // A member that isn't a type leaf — a DNF group `(…)`, a `?`-prefixed
            // member, or end-of-input — bails the whole leaf to a gradual SigRaw.
            $member = self::parseMemberLeaf($tokens, $j);
            if ($member === null) {
                return null;
            }
            [$ref, $lastEnd] = $member;
            $members[] = $ref;
            $i = self::skipWs($tokens, $lastEnd);
        }

        // A different separator still ahead ⇒ a mix that needs DNF parens ⇒ bail.
        // @infection-ignore-all LessThan — `$i < $n` is a defensive bound (a following
        // `)` / `{` always exists); the separator conditions are pinned behaviorally.
        if ($i < $n && ($tokens[$i]->text === '|'
            || ($tokens[$i]->text === '&' && self::sigAmpersandIsIntersection($tokens, $i)))
        ) {
            return null;
        }

        $leaves = array_map(static fn (TypeRef $r): SigTypeRef => new SigTypeRef($r), $members);
        if ($sep === '|') {
            return [new SigUnion($leaves), $lastEnd];
        }
        // A scalar member makes the intersection invalid PHP ⇒ stay gradual (raw).
        foreach ($members as $member) {
            // @infection-ignore-all UnwrapLtrim/UnwrapStrToLower — a scalar keyword is
            // never fully-qualified and arrives lowercased from the grammar, so both
            // normalizations are defensive (same rationale as resolveTypeRef's scalar guard).
            if (in_array(strtolower(ltrim($member->name, '\\')), self::SCALAR_TYPES, true)) {
                return null;
            }
        }
        return [new SigIntersection($leaves), $lastEnd];
    }

    /**
     * Parse one union / intersection MEMBER leaf → `[TypeRef, nextIdx]`, or null if
     * the position isn't a type leaf. Mirrors the primary-leaf parse in
     * {@see parseSigType}: a `Name` / `Name<…>` via {@see parseTypeArg}, or a
     * reserved-word type keyword (`array` / `callable` / `static`).
     *
     * @param list<PhpToken> $tokens
     * @return array{0: TypeRef, 1: int}|null
     */
    private static function parseMemberLeaf(array $tokens, int $i): ?array
    {
        $parsed = self::parseTypeArg($tokens, $i);
        if ($parsed === null) {
            // @infection-ignore-all LessThan UnwrapStrToLower — the `$i < count` bound
            // is defensive (the caller only reaches here mid-span, never at
            // end-of-input); `strtolower` matches the first-leaf keyword branch but
            // the resolver re-lowercases, so it is redundant. (The `$i + 1` end index
            // is NOT ignored — it is pinned by a keyword-member-followed-by-parameter
            // test.)
            if ($i < count($tokens) && self::isSigTypeToken($tokens[$i])) {
                $parsed = [new TypeRef(strtolower($tokens[$i]->text)), $i + 1];
            }
        }
        if ($parsed === null) {
            return null;
        }
        // Member-level `Name[]` sugar lowers to `array`, same as the first leaf.
        [$ref, $next] = $parsed;
        $suffix = self::arraySuffixEnd($tokens, $next);
        if ($suffix !== null && !$ref->isGeneric()) {
            return [new TypeRef('array'), $suffix + 1];
        }
        return $parsed;
    }

    /**
     * The gradual {@see SigRaw} fallback for a compound leaf the flat splitter does
     * not own — spans from `$start` to the end of the type expression.
     *
     * @infection-ignore-all — pure index plumbing: the `?? ($afterLeaf - 1)` arm is
     * unreachable (scanTypeExprEnd returns non-null for the balanced spans that reach a
     * bail), and the `$end + 1` next-index is the parser's shared convention, absorbed
     * by every caller's `skipWs`, so a ±1 shift is unobservable. The raw text is
     * display-only for a gradual leaf. Bail *detection* is pinned behaviorally.
     *
     * @param list<PhpToken> $tokens
     * @return array{0: SigRaw, 1: int}
     */
    private static function rawSigLeaf(array $tokens, string $source, int $start, int $afterLeaf): array
    {
        $end = self::scanTypeExprEnd($tokens, $start) ?? ($afterLeaf - 1);
        return [new SigRaw(self::sliceTokens($tokens, $source, $start, $end)), $end + 1];
    }

    /**
     * True iff the `&` at `$idx` continues an intersection type (a Name follows)
     * rather than marking a by-reference parameter (a `$var` / `...` follows).
     *
     * @infection-ignore-all — flat one-token lookahead; the intersection-vs-by-ref
     * discrimination is pinned by the intersection-by-ref and intersection-member
     * tests, and the residual `$nx < count()` guard is defensive (a `&` is only reached
     * mid-span, never at the token-array boundary).
     *
     * @param list<PhpToken> $tokens
     */
    private static function sigAmpersandIsIntersection(array $tokens, int $idx): bool
    {
        $nx = self::skipWs($tokens, $idx + 1);
        return $nx < count($tokens)
            && (self::isSigTypeToken($tokens[$nx]) || $tokens[$nx]->text === '?');
    }

    /**
     * Source substring spanning token indices `$from`..`$to` inclusive.
     *
     * @param list<PhpToken> $tokens
     */
    private static function sliceTokens(array $tokens, string $source, int $from, int $to): string
    {
        $start = $tokens[$from]->pos;
        $end = $tokens[$to]->pos + strlen($tokens[$to]->text);
        return substr($source, $start, $end - $start);
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
     * @return array{0: list<array{name: string, bound:?BoundDict, default: ?TypeRef, variance: Variance}>, 1: int}|null
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
            // The old Scala/Hack markers `+T` / `-T` were replaced by the
            // Kotlin-style `out T` / `in T`. Reject the glyphs with a migration
            // hint rather than letting them fall through to a bare `null` (which
            // would surface downstream as an opaque PHP syntax error).
            if ($i < $n && ($tokens[$i]->text === '+' || $tokens[$i]->text === '-')) {
                throw new RuntimeException(
                    'The `+T` / `-T` variance syntax was replaced by `out T` / `in T`. '
                    . 'Write `out` for covariance and `in` for contravariance, e.g. '
                    . '`class Box<out T>` or `class Consumer<in T>`.',
                );
            }

            // Kotlin-style variance markers `out` (covariant) / `in`
            // (contravariant). They are contextual keywords: plain `T_STRING`
            // tokens that act as a marker only when immediately followed (past
            // whitespace) by the parameter name — a required space separates
            // marker and name (`out T`, never `outT`, which is one token and an
            // ordinary name). Class-level only: methods, functions, closures,
            // and arrow functions reject variance because their specializations
            // aren't keyed by stable identities that PHP would resolve via
            // `extends` chains.
            $variance = Variance::Invariant;
            if ($i < $n && in_array($tokens[$i]->text, ['out', 'in'], true)) {
                $afterMarker = self::skipWs($tokens, $i + 1);
                if ($afterMarker < $n && self::isNameToken($tokens[$afterMarker])) {
                    if (!$allowVariance) {
                        throw new RuntimeException(
                            'Variance markers `out T` / `in T` are not supported on methods, '
                            . 'functions, closures, or arrow functions — variance is a '
                            . 'class-level-only feature by design: a function or closure '
                            . 'specialization has no stable class identity to anchor a '
                            . 'subtype `extends` edge to. Move the generic to a class-level '
                            . 'type parameter.',
                        );
                    }
                    $variance = $tokens[$i]->text === 'out'
                        ? Variance::Covariant
                        : Variance::Contravariant;
                    $i = $afterMarker;
                }
            }

            if (!self::isNameToken($tokens[$i])) {
                return null;
            }
            $paramName = ltrim($tokens[$i]->text, '\\');
            $i++;

            // Reserve `out` / `in` as variance markers: they can never name a
            // type parameter. This one check at the name slot catches every
            // shape uniformly — `class Box<out>` (no following name, so `out`
            // is read here as the name), `class Box<out out>` (marker consumed,
            // second `out` read as the name), `class Pair<in, out>`,
            // `class Box<in : Foo>` — all reject with a single diagnostic.
            if (in_array($paramName, ['out', 'in'], true)) {
                throw new RuntimeException(
                    '`out` and `in` are variance markers and cannot name a type parameter. '
                    . 'Use `out T` for covariance or `in T` for contravariance, and pick a '
                    . 'different name for the parameter itself.',
                );
            }

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
     * @param list<array{name: string, bound:?BoundDict, default: ?TypeRef}> $entries
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
     * @param list<array{name: string, bound:?BoundDict}> $entries
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
     * @param BoundDict $bound
     */
    private static function boundContainsSelfReference(array $bound, string $paramName): bool
    {
        if ($bound['kind'] === 'leaf') {
            return $bound['name'] === $paramName
                && !$bound['isFq']
                && $bound['args'] === [];
        }
        foreach ($bound['operands'] as $operand) {
            /** @var BoundDict $operand — operands at the recursion boundary lose precision in the alias; the parser guarantees the shape. */
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
     * @return array{0: BoundDict, 1: int}|null
     */
    private static function parseBoundExpr(array $tokens, int $startIdx): ?array
    {
        return self::parseOrBound($tokens, $startIdx);
    }

    /**
     * @param list<PhpToken> $tokens
     * @return array{0: BoundDict, 1: int}|null
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
     * @return array{0: BoundDict, 1: int}|null
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
     * @return array{0: BoundDict, 1: int}|null
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
     * @return array{0: BoundDict, 1: int}|null
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
    /**
     * Equal-length whitespace for a stripped span, keeping every newline byte
     * in place: byte-keyed markers after the span rely on the length, and
     * line-keyed (class / method / name) markers rely on the line count — a
     * multi-line `<…>` clause, turbofish arg list, or sugar span collapsed to
     * spaces would shift every later marker off its node. Deliberately
     * byte-wise (no `/u`): a multibyte character inside the span must blank
     * to one space PER BYTE or the length invariant breaks.
     */
    private static function blank(string $span): string
    {
        return preg_replace('/[^\r\n]/', ' ', $span) ?? '';
    }

    /**
     * Just the newline bytes of a span, for length-CHANGING rewrites (the
     * `T[]` -> `array` sugar) that still must not swallow line breaks.
     */
    private static function newlinesOf(string $span): string
    {
        return preg_replace('/[^\r\n]/', '', $span) ?? '';
    }

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
     * @param list<array{line:int, name:string, kind:string, bytePosition:int, params:list<array{name:string, bound:?BoundDict, default:?TypeRef, variance:Variance}>}> $classMarkers
     * @param list<array{line:int, anchorLine:int, name:string, kind:string, bytePosition:int, args:list<TypeRef>}> $nameMarkers
     * @param list<array{line:int, name:string, kind:string, bytePosition:int, params:list<array{name:string, bound:?BoundDict, default:?TypeRef, variance:Variance}>}> $methodMarkers
     * @param list<array{bytePosition:int, signature:ClosureSignature}> $closureMarkers
     */
    private function resolveAndAttach(array $ast, array $classMarkers, array $nameMarkers, array $methodMarkers, array $closureMarkers, ByteOffsetMap $byteOffsetMap): void
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new
            /**
             * @phpstan-import-type BoundDict from XphpSourceParser
             */
            class($classMarkers, $nameMarkers, $methodMarkers, $closureMarkers, $byteOffsetMap) extends NodeVisitorAbstract {
            private NamespaceContext $ctx;
            /** @var list<list<string>> stack of enclosing type-param scopes */
            private array $typeParamStack = [];

            /**
             * Marker arrays start as lists but become sparse after `unset(...[$i])` as
             * each marker is consumed; foreach iteration order still walks them in
             * insertion order. Typed as `array<int, ...>` rather than `list<...>` so
             * the unset doesn't drift the property type.
             *
             * @param array<int, array{line:int, name:string, kind:string, bytePosition:int, params:list<array{name:string, bound:?BoundDict, default:?TypeRef, variance:Variance}>}> $classMarkers
             * @param array<int, array{line:int, anchorLine:int, name:string, kind:string, bytePosition:int, args:list<TypeRef>}> $nameMarkers
             * @param array<int, array{line:int, name:string, kind:string, bytePosition:int, params:list<array{name:string, bound:?BoundDict, default:?TypeRef, variance:Variance}>}> $methodMarkers
             * @param array<int, array{bytePosition:int, signature:ClosureSignature}> $closureMarkers
             */
            public function __construct(
                private array $classMarkers,
                private array $nameMarkers,
                private array $methodMarkers,
                private array $closureMarkers,
                private ByteOffsetMap $byteOffsetMap,
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
                    foreach ($node->stmts as $inner) {
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
                        // Match on the NAME Identifier's line, not the node's:
                        // the node starts at its first attribute group or
                        // modifier, which can sit on an earlier line
                        // (`#[Attr]\nfinal class Box<T>`), while the marker
                        // records the name token's line.
                        if ($marker['line'] === $node->name->getStartLine() && $marker['name'] === $shortName) {
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
                    // Named templates match by (name line, name); anonymous templates
                    // (closures + arrows) match by (kind, bytePosition). The marker
                    // records the ORIGINAL-source byte of the node's first token (the
                    // first attribute group, `static`, or the keyword itself),
                    // while getStartFilePos() reports the STRIPPED-source
                    // byte -- a length-changing rewrite earlier in the file (e.g.
                    // `LongName[]` -> `array`) shifts the two apart, so the stripped
                    // position maps back through the byte-offset map before comparing
                    // (same translation as attachClosureSig below).
                    $isAnonymous = $node instanceof Node\Expr\Closure
                        || $node instanceof Node\Expr\ArrowFunction;
                    $declName = $isAnonymous ? '' : $node->name->toString();
                    // Named declarations match on the NAME Identifier's line —
                    // the node itself starts at its first attribute group or
                    // modifier, which can sit on an earlier line
                    // (`#[Attr]\npublic function wrap<T>`), while the marker
                    // records the name token's line.
                    $declLine = $isAnonymous ? -1 : $node->name->getStartLine();
                    $nodeStartByte = $this->byteOffsetMap->toOriginal($node->getStartFilePos());
                    $matchedParamNames = [];
                    foreach ($this->methodMarkers as $i => $marker) {
                        // @infection-ignore-all -- markers are populated jointly with
                        // both halves of the (line, name) or (kind, bytePosition) pair;
                        // any single-clause-only input is unreachable from the scanner.
                        $isMatch = $isAnonymous
                            ? ($marker['kind'] !== 'named'
                                && $marker['bytePosition'] === $nodeStartByte)
                            : ($marker['line'] === $declLine
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

                // Tag bare class/interface Name references in class-name positions
                // with their resolved FQN. Reached from the *parent* node's slots
                // (never via a blanket Name visit) so function-call and constant
                // Names are left bare -- PHP's global fallback covers them, and
                // fully-qualifying them would break it. Runs after the Namespace_/
                // Use_ branches (so $ctx is populated) and after the ClassLike/
                // method type-param push (so isEnclosingTypeParam sees this scope).
                if ($node instanceof Node\Stmt\Class_) {
                    $this->markType($node->extends);
                    foreach ($node->implements as $impl) {
                        $this->markName($impl);
                    }
                } elseif ($node instanceof Node\Stmt\Interface_) {
                    foreach ($node->extends as $ext) {
                        $this->markName($ext);
                    }
                // Enums are intentionally absent: they can't be generic, so they
                // are never cloned into the XPHP\Generated\... namespace, and a
                // resolved-FQN attribute on an enum's `implements` would never be
                // consumed by the Specializer swap.
                } elseif ($node instanceof Node\Expr\New_
                    || $node instanceof Node\Expr\Instanceof_
                    || $node instanceof Node\Expr\StaticCall
                    || $node instanceof Node\Expr\ClassConstFetch
                    || $node instanceof Node\Expr\StaticPropertyFetch
                ) {
                    if ($node->class instanceof Name) {
                        $this->markName($node->class);
                    }
                } elseif ($node instanceof Node\Stmt\Catch_) {
                    foreach ($node->types as $type) {
                        $this->markName($type);
                    }
                } elseif ($node instanceof Node\Param) {
                    $this->markType($node->type);
                } elseif ($node instanceof Node\Stmt\Property) {
                    $this->markType($node->type);
                } elseif ($node instanceof Node\Stmt\ClassConst) {
                    $this->markType($node->type);
                } elseif ($node instanceof Node\Stmt\ClassMethod
                    || $node instanceof Node\Stmt\Function_
                    || $node instanceof Node\Expr\Closure
                    || $node instanceof Node\Expr\ArrowFunction
                ) {
                    $this->markType($node->returnType);
                }

                return null;
            }

            /**
             * Tag every class-name Name leaf inside a type-hint slot (recursing
             * through nullable/union/intersection wrappers). Scalar `Identifier`
             * leaves and non-Name expressions are left untouched.
             */
            private function markType(?Node $type): void
            {
                if ($type instanceof Name) {
                    $this->attachClosureSig($type);
                    $this->markName($type);
                } elseif ($type instanceof Node\NullableType) {
                    $this->markType($type->type);
                } elseif ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
                    foreach ($type->types as $inner) {
                        $this->markType($inner);
                    }
                }
            }

            /**
             * If a `\Closure` type Name is the erased head of a closure signature
             * (the `(…)[: ret]` was blanked at scan time, leaving this surviving
             * `\Closure`), attach the resolved signature. Matched by BYTE POSITION,
             * not by line: a plain `Closure` type hint sharing a line with a real
             * signature (`Closure $a, Closure(int): int $b`) would otherwise steal the
             * marker. The node's stripped-source start position maps back through the
             * byte-offset map to the original `Closure` position the marker recorded.
             */
            private function attachClosureSig(Name $node): void
            {
                // @infection-ignore-all ReturnRemoval — fast-path guard: a
                // non-Closure name can never match a marker anyway (each
                // recorded bytePosition holds the erased `\Closure` name
                // itself), so falling through only wastes loop work.
                if (ltrim($node->toString(), '\\') !== 'Closure') {
                    return;
                }
                $startPos = $node->getStartFilePos();
                // @infection-ignore-all — defensive: getStartFilePos() only returns < 0
                // when positions are unrecorded; a real Name never sits at byte 0 (the
                // open tag), so shifting this boundary is unreachable with valid input.
                if ($startPos < 0) {
                    return;
                }
                $originalPos = $this->byteOffsetMap->toOriginal($startPos);
                foreach ($this->closureMarkers as $i => $marker) {
                    if ($marker['bytePosition'] === $originalPos) {
                        $node->setAttribute(
                            XphpSourceParser::ATTR_CLOSURE_SIG,
                            $this->resolveClosureSignature($marker['signature']),
                        );
                        unset($this->closureMarkers[$i]);
                        // @infection-ignore-all — break vs continue is equivalent after unset:
                        // bytePosition is unique, so no later marker can match again.
                        break;
                    }
                }
            }

            private function resolveClosureSignature(ClosureSignature $sig): ClosureSignature
            {
                $params = array_map(
                    fn (ClosureSignatureParam $p): ClosureSignatureParam => new ClosureSignatureParam(
                        $this->resolveSigType($p->type),
                        $p->byRef,
                        $p->variadic,
                        $p->optional,
                    ),
                    $sig->params,
                );
                $return = $sig->return === null ? null : $this->resolveSigType($sig->return);

                return new ClosureSignature($params, $return, $sig->nullable);
            }

            private function resolveSigType(SigType $type): SigType
            {
                if ($type instanceof SigTypeRef) {
                    return new SigTypeRef($this->resolveTypeRef($type->type));
                }
                if ($type instanceof SigClosure) {
                    return new SigClosure($this->resolveClosureSignature($type->signature));
                }
                if ($type instanceof SigUnion) {
                    return new SigUnion(array_map($this->resolveSigType(...), $type->members));
                }
                if ($type instanceof SigIntersection) {
                    return new SigIntersection(array_map($this->resolveSigType(...), $type->members));
                }

                // SigRaw — a leaf the flat splitter could not structure (a DNF group,
                // an intersection with a scalar); carry the raw text through gradual.
                return $type;
            }

            private function markName(Name $node): void
            {
                if (!$this->shouldQualify($node)) {
                    return;
                }
                $name = $node->toString();
                $resolved = $this->ctx->resolveAgainstContext($name);
                $node->setAttribute(XphpSourceParser::ATTR_RESOLVED_FQN, $resolved);

                // Flag a bare, single-segment, non-imported class reference used inside a
                // generic context. shouldQualify() already excluded declared type-params,
                // scalars, FQ names, and generic-arg-bearing names, so what's left is either
                // a real (in-project / built-in) type or a stray/undeclared type parameter
                // like the `T` in `interface Foo<Z> { add(T $x): void; }`. The validator
                // resolves which using the declared-set; here we only record the suspicion.
                if (count($node->getParts()) === 1
                    && $this->hasEnclosingTypeParams()
                    && !$this->ctx->isImported($name)
                ) {
                    $node->setAttribute(XphpSourceParser::ATTR_SUSPECT_UNDECLARED_TYPE, $resolved);
                }
            }

            /**
             * A bare class/interface Name is fully-qualifiable only when it is not
             * already absolute, carries no generic args (those are rewritten by the
             * generic machinery, which keys on `!isFullyQualified()`), is not an
             * enclosing type-param, and is not a scalar / self / parent / static
             * keyword (all of which live in SCALAR_TYPES).
             */
            private function shouldQualify(Name $node): bool
            {
                // @infection-ignore-all — defensive only: a FullyQualified name that slipped
                // through would still be skipped by the Specializer swap (which guards on
                // `!isFullyQualified()`), so tagging or not tagging it is unobservable. The
                // check just avoids stamping a useless attribute.
                if ($node instanceof Node\Name\FullyQualified) {
                    return false;
                }
                if ($node->getAttribute(XphpSourceParser::ATTR_GENERIC_ARGS) !== null) {
                    return false;
                }
                $name = $node->toString();
                // @infection-ignore-all — defensive only: an enclosing type-param is a single
                // segment that the Specializer substitutes (returning early via typeRefToNode)
                // before the resolved-FQN swap can ever read the attribute, so tagging it is
                // likewise unobservable.
                if ($this->isEnclosingTypeParam($name)) {
                    return false;
                }
                $parts = $node->getParts();
                // @infection-ignore-all — scalar keywords are single-segment; the count guard only
                // skips a needless strtolower on namespaced names and never changes the outcome.
                if (count($parts) === 1 && in_array(strtolower($parts[0]), XphpSourceParser::SCALAR_TYPES, true)) {
                    return false;
                }
                return true;
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
             * @param array{name: string, bound:?BoundDict} $entry
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
             * @param array{name: string, bound:?BoundDict, default: ?TypeRef, variance: Variance} $entry
             */
            private function buildDefault(array $entry): ?TypeRef
            {
                if ($entry['default'] === null) {
                    return null;
                }
                return $this->resolveTypeRef($entry['default']);
            }

            /**
             * @param BoundDict $node
             */
            private function buildBoundExprNode(array $node): BoundExpr
            {
                if ($node['kind'] === 'leaf') {
                    $resolvedArgs = $this->resolveTypeRefList($node['args']);
                    // A bound that is a bare enclosing type parameter (`U : E`, or `B : A` over an
                    // earlier param) is kept as an `isTypeParam` TypeRef -- mirroring resolveTypeRef
                    // -- so the call site can ground it against the receiver's concrete argument
                    // rather than treating `E` as a phantom class name.
                    if (!$node['isFq'] && $this->isEnclosingTypeParam($node['name'])) {
                        return new BoundLeaf(new TypeRef($node['name'], $resolvedArgs, isTypeParam: true));
                    }
                    // A scalar/builtin-keyword leaf (`T : int|string`) must stay unqualified and flagged
                    // isScalar -- mirroring resolveTypeRef -- so the bound check compares
                    // scalar-against-scalar. Otherwise resolveNameOnly would namespace-qualify it (`int` ->
                    // `Ns\int`) and every valid scalar argument would be rejected. SCALAR_TYPES holds only
                    // reserved keywords, so a class that aliases a scalar (`<T : Double>`) correctly falls
                    // through to class resolution below.
                    $lowerName = strtolower($node['name']);
                    if (!$node['isFq'] && in_array($lowerName, XphpSourceParser::SCALAR_TYPES, true)) {
                        // @infection-ignore-all TrueValue -- equivalent: a bound leaf's isScalar flag is
                        // never read (the bound check keys on the TypeRef name; the only isScalar readers
                        // are instantiation-argument / variance paths, not bound leaves). It is set true
                        // solely to mirror resolveTypeRef's scalar branch, so true vs false is unobservable.
                        return new BoundLeaf(new TypeRef($lowerName, $resolvedArgs, isScalar: true));
                    }
                    $fqn = $node['isFq']
                        ? $node['name']
                        : $this->resolveNameOnly($node['name']);
                    $suspect = !$node['isFq']
                        && $this->isSuspectUndeclared($node['name']);
                    return new BoundLeaf(new TypeRef($fqn, $resolvedArgs, suspectUndeclared: $suspect));
                }
                $operands = [];
                foreach ($node['operands'] as $op) {
                    /** @var BoundDict $op — operands at the recursion boundary lose precision in the alias; the parser guarantees the shape. */
                    $operands[] = $this->buildBoundExprNode($op);
                }
                if ($node['kind'] === 'and') {
                    return new BoundIntersection(...$operands);
                }
                return new BoundUnion(...$operands);
            }

            public function leaveNode(Node $node): null
            {
                // NB: variance-position validation no longer runs here. It moved to a
                // Registry validation phase (`validateVariancePositions`) that runs over
                // collected definitions, so `xphp check` can collect every variance error
                // across all files in one run instead of aborting at the first parse.
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

                return new TypeRef(
                    $this->ctx->resolveAgainstContext($name),
                    $resolvedArgs,
                    suspectUndeclared: $this->isSuspectUndeclared($name),
                );
            }

            /**
             * A bare, single-segment, non-imported class name used inside a generic
             * context — the suspect condition shared by the bound/default TypeRef path
             * and {@see markName}'s ATTR_SUSPECT_UNDECLARED_TYPE tag. Callers have
             * already excluded scalars and enclosing type-params.
             */
            private function isSuspectUndeclared(string $name): bool
            {
                // @infection-ignore-all UnwrapStrToLower -- scalar-type tokens already arrive
                // lowercased from the grammar (same rationale as resolveTypeRef's scalar guard).
                return strpos($name, '\\') === false
                    && !in_array(strtolower($name), XphpSourceParser::SCALAR_TYPES, true)
                    && $this->hasEnclosingTypeParams()
                    && !$this->ctx->isImported($name);
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

            /**
             * True when some enclosing scope declares type parameters — i.e. we're
             * inside a generic template or a generic method/function/closure. Every
             * class/method pushes a frame (empty for non-generic ones), so this asks
             * whether any frame is non-empty rather than whether the stack is non-empty.
             */
            private function hasEnclosingTypeParams(): bool
            {
                foreach ($this->typeParamStack as $scope) {
                    if ($scope !== []) {
                        return true;
                    }
                }
                return false;
            }
        });

        $traverser->traverse($ast);
    }
}
