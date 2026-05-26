<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler\SemanticTokens;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpToken;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\ByteOffsetMap;

/**
 * Walk the xphp source + AST and emit {@see TokenSpec} entries for the
 * PHP-shaped surface (keywords, variables, numbers, strings, comments,
 * class / interface / enum / function / method / property names) plus
 * the xphp generic forms (Slice 3, not yet implemented).
 *
 * Two passes:
 *
 * 1. **Token scan** via PHP's built-in {@see PhpToken::tokenize}.  The
 *    tokens are byte-indexed into the ORIGINAL source (not the stripped
 *    buffer), so positions feed directly into {@see PositionMap}.
 *    Emits keywords, variables, numbers, single-quoted strings,
 *    double-quoted strings (as a single span -- interpolation
 *    classification is deferred to Slice 4), and comments.  Deliberately
 *    skips T_STRING (identifiers) so the AST pass can classify each
 *    identifier into its semantic role without overlap.
 *
 * 2. **AST walk** of the nikic-parsed tree.  AST node offsets index
 *    into the STRIPPED source (`<...>` clauses excised), so positions
 *    pass through {@see ByteOffsetMap::toOriginal} before
 *    {@see PositionMap}.  Emits the identifier kinds the token scan
 *    can't classify on its own: ClassLike names -> `class` /
 *    `interface` / `enum`, ClassMethod names -> `method`, Function_
 *    names -> `function`, PropertyItem names -> `property`, Param
 *    names -> `parameter`.
 *
 * Slice 3 will extend the AST pass to recognise xphp generic
 * `ATTR_GENERIC_PARAMS` / `ATTR_GENERIC_ARGS` decorations and emit
 * `typeParameter` for every `T` in the 12 audit forms.
 */
final class AstVisitor
{
    /**
     * @var array<int, string>  T_* token-id -> semantic-token type for the
     *                          subset PhpToken-based classification covers.
     */
    private static array $tokenTypeMap;

    public function __construct(
        private readonly PositionMap $positionMap,
        private readonly ByteOffsetMap $byteOffsetMap,
        private readonly string $source,
    ) {
        if (!isset(self::$tokenTypeMap)) {
            self::$tokenTypeMap = self::buildTokenTypeMap();
        }
    }

    /**
     * @param  array<int, Node> $stmts
     * @return list<TokenSpec>
     */
    public function visit(array $stmts): array
    {
        // AST walk runs FIRST and collects the byte-ranges where a
        // T_VARIABLE token should re-classify as `parameter` instead of
        // `variable`.  The token pass then SKIPS T_VARIABLE at those
        // ranges and emits the parameter spec on the AST visitor's
        // behalf.  This replaces the older "emit both, hope the client
        // honours later-wins" approach -- single spec per source span,
        // half the response size at every parameter.
        $specs = [];
        $reclassifyVariableAt = [];

        if ($stmts !== []) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor($this->newAstWalker($specs, $reclassifyVariableAt));
            $traverser->traverse($stmts);
        }

        $this->collectFromTokens($specs, $reclassifyVariableAt);

        return $specs;
    }

    /**
     * Pass 1: tokenize the original source and emit specs for the token
     * classes that don't need AST context.
     *
     * @param list<TokenSpec> $out
     */
    /**
     * @param array<int, string>      $reclassifyVariableAt  byte-offset -> alternative type
     *                                                       (currently `parameter`); when a
     *                                                       T_VARIABLE starts at that offset
     *                                                       we emit the alternative type
     *                                                       INSTEAD of `variable`.
     * @param list<TokenSpec>         $out
     */
    private function collectFromTokens(array &$out, array $reclassifyVariableAt = []): void
    {
        // Non-strict tokenization (flags=0).  TOKEN_PARSE turns
        // PhpToken into a strict-mode tokenizer that throws ParseError
        // on the `<T>` we use for generics.  In non-strict mode the
        // `<` and `T` just come back as their literal tokens.
        $tokens = @PhpToken::tokenize($this->source);
        if ($tokens === false) {
            return;
        }

        // Slice 3: state machine tracks whether we're inside a
        // `<...>` generic clause.  `<` opens a clause if (a) the
        // previous non-trivial token was an identifier (T_STRING),
        // and (b) the next non-trivial token is an uppercase-starting
        // identifier or backslash (FQN start).  This rejects
        // `$size < count($items)` (LHS is T_VARIABLE, not T_STRING)
        // and `Foo::BAR < 5` (RHS is a number, not uppercase ident).
        // Inside a clause: T_STRING tokens emit as `typeParameter`,
        // backslashes as part of FQNs (left unclassified -- the
        // surrounding T_STRING segments paint).  Depth-counted so
        // nested `Box<Lst<T>>` still classifies T.
        $genericDepth = 0;
        $lastSignificantTokenId = null;

        $tokenCount = count($tokens);
        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];

            // PhpToken's `$id` is always int: for T_* tokens it's the
            // T_* constant; for single-char tokens (`<`, `>`, `,`, ...)
            // it's the literal byte value.  Distinguish via the range.
            $isNamedToken = $token->id >= 256;

            // Treat whitespace + comments as "trivial" for state purposes
            // (they don't update lastSignificantTokenId and they don't
            // exit a clause).  Their own classification still happens
            // below.
            $isTrivial = $isNamedToken
                && in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);

            // Open / close angle-clause state on single-char tokens.
            if (!$isNamedToken && $token->text === '<') {
                if ($genericDepth > 0) {
                    $genericDepth++;
                } elseif ($lastSignificantTokenId === T_STRING
                    && self::peekIsUppercaseIdent($tokens, $i + 1)
                ) {
                    $genericDepth = 1;
                }
            } elseif (!$isNamedToken && $token->text === '>' && $genericDepth > 0) {
                $genericDepth--;
            }

            // Classify the token.
            if ($isNamedToken) {
                $type = self::$tokenTypeMap[$token->id] ?? null;
                if ($type === 'variable' && isset($reclassifyVariableAt[$token->pos])) {
                    // AST pass marked this T_VARIABLE position as a
                    // parameter; emit `parameter` instead of `variable`
                    // (single spec, half the response size).
                    $type = $reclassifyVariableAt[$token->pos];
                }
                if ($type === null && $genericDepth > 0 && self::isIdentInGenericClause($token->id)) {
                    // Inside a generic clause an identifier is a type
                    // name -- emit as `typeParameter` for the LSP-spec
                    // standard classification.  Covers bare T_STRING
                    // (`T`) and qualified-name tokens
                    // (T_NAME_FULLY_QUALIFIED `\Stringable`,
                    // T_NAME_QUALIFIED `App\Foo`, T_NAME_RELATIVE
                    // `namespace\Foo`).
                    $type = 'typeParameter';
                }
                if ($type === null && $token->id === T_STRING && self::isReservedWordIdent($token->text)) {
                    // PHP tokenizes `null`, `true`, `false`, `void`,
                    // `mixed`, `never`, `iterable`, `self`, `parent`,
                    // `static` (as a type), and the primitive scalar
                    // names `int` / `string` / `bool` / `float` /
                    // `array` / `object` as T_STRING -- not as their
                    // own T_* constants.  Without this case they fall
                    // through to "no classification" and the editor
                    // paints them with the default text color.  The
                    // user-visible effect: `null` looks like an
                    // identifier instead of a keyword.  Lookup is
                    // case-insensitive because PHP itself accepts
                    // `NULL`, `Null`, `null` interchangeably.
                    $type = 'keyword';
                }
                if ($type !== null) {
                    $this->emit($out, $token->pos, strlen($token->text), $type);
                }
            }

            if (!$isTrivial) {
                $lastSignificantTokenId = $isNamedToken ? $token->id : null;
            }
        }
    }

    /**
     * PHP reserved-word identifiers tokenized as T_STRING.
     *
     * `null`, `true`, `false` are constants treated as keywords by
     * developer convention but emitted as bareword T_STRING by PHP's
     * tokenizer.  Type-name primitives (`int`, `string`, etc.) follow
     * the same pattern.  Lookup is case-insensitive because PHP
     * accepts `NULL`/`Null`/`null` interchangeably.
     */
    private const RESERVED_WORD_IDENTIFIERS = [
        'null' => true,
        'true' => true,
        'false' => true,
        'void' => true,
        'mixed' => true,
        'never' => true,
        'iterable' => true,
        'self' => true,
        'parent' => true,
        'int' => true,
        'string' => true,
        'bool' => true,
        'float' => true,
        'array' => true,
        'object' => true,
        'callable' => true,
    ];

    private static function isReservedWordIdent(string $text): bool
    {
        return isset(self::RESERVED_WORD_IDENTIFIERS[strtolower($text)]);
    }

    /**
     * Token ids that count as "an identifier" inside a generic clause.
     * Covers PHP 8.0+ qualified-name tokens too -- `\Stringable` comes
     * back as one T_NAME_FULLY_QUALIFIED, not `\` + T_STRING.
     */
    private static function isIdentInGenericClause(int $tokenId): bool
    {
        return $tokenId === T_STRING
            || $tokenId === T_NAME_QUALIFIED
            || $tokenId === T_NAME_FULLY_QUALIFIED
            || $tokenId === T_NAME_RELATIVE;
    }

    /**
     * Peek forward in the token stream skipping whitespace + comments.
     * Returns true if the next significant token is a T_STRING starting
     * with an uppercase letter / underscore / backslash (FQN start) --
     * the "this `<` opens a generic clause" heuristic.
     *
     * @param array<int, \PhpToken> $tokens
     */
    private static function peekIsUppercaseIdent(array $tokens, int $startIdx): bool
    {
        $count = count($tokens);
        for ($i = $startIdx; $i < $count; $i++) {
            $t = $tokens[$i];
            $isNamed = $t->id >= 256;
            if ($isNamed && in_array($t->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($isNamed && $t->id === T_STRING) {
                $first = $t->text[0] ?? '';
                return ($first >= 'A' && $first <= 'Z') || $first === '_';
            }
            if (!$isNamed && $t->text === '\\') {
                return true; // FQN like `<\App\User>`
            }
            return false;
        }
        return false;
    }

    /**
     * Pass 2: walk the AST and emit specs for identifier kinds that
     * the token scan can't classify on its own.
     *
     * Maintains a stack of in-scope type-param names (from
     * {@see \XPHP\Transpiler\Monomorphize\XphpSourceParser::ATTR_GENERIC_PARAMS}
     * on the enclosing ClassLike) so reified-T references inside
     * generic class bodies (`new T()`, `T::class`, `instanceof T`,
     * `T::method()`) re-classify as `typeParameter`.  The token-scan
     * pass can't make this distinction -- it sees `new T()` the same
     * way it sees `new User()` -- so the AST walk is the only place
     * with the scope information.
     *
     * @param list<TokenSpec>              &$out
     * @param array<int, string>           &$reclassifyVariableAt  ORIGINAL-source
     *                                                              byte-offset ->
     *                                                              alternative type
     *                                                              for the T_VARIABLE
     *                                                              that starts there.
     */
    private function newAstWalker(array &$out, array &$reclassifyVariableAt): NodeVisitorAbstract
    {
        $visitor = new class($out, $reclassifyVariableAt, $this) extends NodeVisitorAbstract {
            /**
             * Stack of in-scope type-param name sets.  Each frame is the
             * set of names declared on an enclosing ClassLike via
             * ATTR_GENERIC_PARAMS.  Frames are pushed in enterNode and
             * popped in leaveNode.
             *
             * @var list<array<string, true>>
             */
            private array $typeParamStack = [];

            /**
             * @param list<TokenSpec>     $out
             * @param array<int, string>  $reclassifyVariableAt
             */
            public function __construct(
                private array &$out,
                private array &$reclassifyVariableAt,
                private AstVisitor $emitter,
            ) {
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof ClassLike) {
                    $params = $node->getAttribute(\XPHP\Transpiler\Monomorphize\XphpSourceParser::ATTR_GENERIC_PARAMS);
                    if (is_array($params) && $params !== []) {
                        $frame = [];
                        foreach ($params as $param) {
                            if ($param instanceof \XPHP\Transpiler\Monomorphize\TypeParam) {
                                $frame[$param->name] = true;
                            }
                        }
                        $this->typeParamStack[] = $frame;
                    } else {
                        // Push an empty frame anyway so leaveNode's pop
                        // pairs symmetrically.  Empty frame doesn't add
                        // type-param names but maintains stack depth.
                        $this->typeParamStack[] = [];
                    }
                    if ($node->name !== null) {
                        $this->emitter->emitAstIdentifier(
                            $this->out,
                            $node->name,
                            self::classLikeType($node),
                        );
                    }
                    return null;
                }
                if ($node instanceof ClassMethod) {
                    $this->emitter->emitAstIdentifier($this->out, $node->name, 'method');
                    return null;
                }
                if ($node instanceof Function_) {
                    $this->emitter->emitAstIdentifier($this->out, $node->name, 'function');
                    return null;
                }
                if ($node instanceof Name) {
                    // Reified-T detection: single-segment Name whose text
                    // matches an in-scope type-param.  Covers `new T()`,
                    // `instanceof T`, the class part of `T::method()` /
                    // `T::class`, and any other use of T as a class-name
                    // slot inside a generic body.
                    if (!$node->isFullyQualified() && count($node->getParts()) === 1) {
                        $name = $node->getParts()[0];
                        if ($this->isInScopeTypeParam($name)) {
                            $start = $node->getStartFilePos();
                            $end = $node->getEndFilePos();
                            if ($start >= 0 && $end >= $start) {
                                $this->emitter->emitAstSpan(
                                    $this->out,
                                    $start,
                                    $end - $start + 1,
                                    'typeParameter',
                                );
                            }
                        }
                    }
                    return null;
                }
                if ($node instanceof PropertyItem) {
                    // PropertyItem->name is a VarLikeIdentifier (no leading `$`
                    // in the AST but the `$` IS in the source span).  Skip
                    // re-emit; T_VARIABLE in pass 1 already covered it.
                    return null;
                }
                if ($node instanceof Param && $node->var instanceof Node\Expr\Variable) {
                    // Re-classify the param variable from `variable` to
                    // `parameter`.  We don't emit a separate spec here;
                    // instead we mark the ORIGINAL-source byte offset
                    // and the token pass picks it up, replacing its
                    // own `variable` emit with `parameter` at that
                    // offset.  Single spec per source span, half the
                    // wire size vs the previous "emit both, hope
                    // later-wins" approach.
                    $name = $node->var->name;
                    if (is_string($name)) {
                        $strippedStart = $node->var->getStartFilePos();
                        if ($strippedStart >= 0) {
                            $origStart = $this->emitter->mapToOriginal($strippedStart);
                            if ($origStart >= 0) {
                                $this->reclassifyVariableAt[$origStart] = 'parameter';
                            }
                        }
                    }
                    return null;
                }
                return null;
            }

            public function leaveNode(Node $node)
            {
                if ($node instanceof ClassLike && $this->typeParamStack !== []) {
                    array_pop($this->typeParamStack);
                }
                return null;
            }

            private function isInScopeTypeParam(string $name): bool
            {
                foreach ($this->typeParamStack as $frame) {
                    if (isset($frame[$name])) {
                        return true;
                    }
                }
                return false;
            }

            private static function classLikeType(ClassLike $node): string
            {
                if ($node instanceof Interface_) {
                    return 'interface';
                }
                if ($node instanceof Enum_) {
                    return 'enum';
                }
                if ($node instanceof Trait_) {
                    // No `trait` in LSP standard token types; map to `class`.
                    return 'class';
                }
                return 'class';
            }
        };
        return $visitor;
    }

    /**
     * Emit a spec at the given ORIGINAL-source byte offset.  Internal --
     * shared by both passes; the token pass calls directly, the AST pass
     * calls {@see emitAstSpan} which translates from stripped to
     * original first.
     *
     * @internal exposed for the anonymous AST visitor; not a public API
     *
     * @param list<TokenSpec> $out
     */
    public function emit(array &$out, int $originalOffset, int $length, string $type, array $modifiers = []): void
    {
        if ($length <= 0) {
            return;
        }
        if ($originalOffset < 0 || $originalOffset > strlen($this->source)) {
            return;
        }
        [$line, $startChar] = $this->positionMap->offsetToPosition($originalOffset);
        // Length stays in BYTES at this point -- correct for ASCII-only
        // identifiers (the vast majority of PHP source).  LSP wants
        // UTF-16 code units; for ASCII the two are equal.  Non-ASCII
        // tokens (e.g. UTF-8 strings) are an edge case Slice 4 covers.
        $out[] = new TokenSpec(
            line: $line,
            startChar: $startChar,
            length: $length,
            type: $type,
            modifiers: $modifiers,
        );
    }

    /**
     * Translate a STRIPPED-source byte offset to the ORIGINAL source.
     *
     * @internal exposed for the anonymous AST visitor's reclassify map
     */
    public function mapToOriginal(int $strippedOffset): int
    {
        return $this->byteOffsetMap->toOriginal($strippedOffset);
    }

    /**
     * Emit a spec from a STRIPPED-source byte span.  Translates the start
     * + end through {@see ByteOffsetMap} before delegating to
     * {@see emit}.
     *
     * @internal exposed for the anonymous AST visitor
     *
     * @param list<TokenSpec> $out
     */
    public function emitAstSpan(array &$out, int $strippedStart, int $length, string $type, array $modifiers = []): void
    {
        $origStart = $this->byteOffsetMap->toOriginal($strippedStart);
        $origEnd = $this->byteOffsetMap->toOriginal($strippedStart + $length);
        if ($origStart < 0 || $origEnd < $origStart) {
            return;
        }
        $this->emit($out, $origStart, $origEnd - $origStart, $type, $modifiers);
    }

    /**
     * @internal exposed for the anonymous AST visitor
     *
     * @param list<TokenSpec> $out
     */
    public function emitAstIdentifier(array &$out, Identifier $identifier, string $type): void
    {
        $start = $identifier->getStartFilePos();
        $end = $identifier->getEndFilePos();
        if ($start < 0 || $end < $start) {
            return;
        }
        $this->emitAstSpan($out, $start, $end - $start + 1, $type);
    }

    /**
     * @return array<int, string>
     */
    private static function buildTokenTypeMap(): array
    {
        $map = [];

        // Variables.
        $map[T_VARIABLE] = 'variable';

        // Numbers.
        $map[T_LNUMBER] = 'number';
        $map[T_DNUMBER] = 'number';

        // Strings.  Single-quoted strings + the surrounding double-quote
        // spans for un-interpolated string content.  Interpolation paths
        // (T_DOUBLE_QUOTES + T_ENCAPSED_AND_WHITESPACE + inner T_VARIABLE)
        // are decomposed by the tokenizer; the variable bits already get
        // picked up via T_VARIABLE, and the literal slabs become
        // T_ENCAPSED_AND_WHITESPACE which we also classify as string.
        $map[T_CONSTANT_ENCAPSED_STRING] = 'string';
        $map[T_ENCAPSED_AND_WHITESPACE] = 'string';

        // Comments.
        $map[T_COMMENT] = 'comment';
        $map[T_DOC_COMMENT] = 'comment';

        // Keywords.  Curated subset -- every PHP reserved word that
        // appears in normal code.  Magic constants (__CLASS__ etc.) and
        // less-common tokens (T_HALT_COMPILER, T_LIST) are not in the
        // map; they fall through to no-classification.
        $keywordTokens = [
            T_ABSTRACT,
            T_AS,
            T_BREAK,
            T_CALLABLE,
            T_CASE,
            T_CATCH,
            T_CLASS,
            T_CLONE,
            T_CONST,
            T_CONTINUE,
            T_DECLARE,
            T_DEFAULT,
            T_DO,
            T_ECHO,
            T_ELSE,
            T_ELSEIF,
            T_EMPTY,
            T_ENDDECLARE,
            T_ENDFOR,
            T_ENDFOREACH,
            T_ENDIF,
            T_ENDSWITCH,
            T_ENDWHILE,
            T_ENUM,
            T_EXIT,
            T_EXTENDS,
            T_FINAL,
            T_FINALLY,
            T_FN,
            T_FOR,
            T_FOREACH,
            T_FUNCTION,
            T_GLOBAL,
            T_GOTO,
            T_IF,
            T_IMPLEMENTS,
            T_INCLUDE,
            T_INCLUDE_ONCE,
            T_INSTANCEOF,
            T_INSTEADOF,
            T_INTERFACE,
            T_ISSET,
            T_MATCH,
            T_NAMESPACE,
            T_NEW,
            T_OPEN_TAG,
            T_OPEN_TAG_WITH_ECHO,
            T_PRINT,
            T_PRIVATE,
            T_PROTECTED,
            T_PUBLIC,
            T_READONLY,
            T_REQUIRE,
            T_REQUIRE_ONCE,
            T_RETURN,
            T_STATIC,
            T_SWITCH,
            T_THROW,
            T_TRAIT,
            T_TRY,
            T_UNSET,
            T_USE,
            T_VAR,
            T_WHILE,
            T_YIELD,
            T_YIELD_FROM,
        ];
        foreach ($keywordTokens as $id) {
            $map[$id] = 'keyword';
        }

        return $map;
    }
}
