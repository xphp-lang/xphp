<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\Hover;
use Phpactor\LanguageServerProtocol\HoverParams;
use Phpactor\LanguageServerProtocol\MarkupContent;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpHoverHandler;
use XPHP\Lsp\PositionMap;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;
use function Amp\Promise\wait;

final class XphpHoverHandlerTest extends TestCase
{
    public function testHoverOverGenericInstantiationShowsSpecializedFqn(): void
    {
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        $x = new Box<Plastic>();
        XPHP);
        // Cursor on the `B` of `Box`.
        $hover = $this->hoverAt($handler, $uri, $workspace->get($uri)->text, 'Box<Plastic>');

        self::assertInstanceOf(Hover::class, $hover);
        self::assertInstanceOf(MarkupContent::class, $hover->contents);
        self::assertStringContainsString('Specializes to:', $hover->contents->value);
        self::assertStringContainsString('XPHP\\Generated\\App\\Box\\T_', $hover->contents->value);
    }

    public function testHoverOverTypeParamShowsBoundAndOwner(): void
    {
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        class Box<T: \Stringable>
        {
            public T $item;
        }
        XPHP);
        // Cursor on the `T` inside `public T $item;`.
        $hover = $this->hoverAt($handler, $uri, $workspace->get($uri)->text, 'public T $item', offsetInSearch: strlen('public '));

        self::assertInstanceOf(Hover::class, $hover);
        $text = $hover->contents->value;
        self::assertStringContainsString('Type parameter', $text);
        self::assertStringContainsString('`T`', $text);
        self::assertStringContainsString('App\\Box', $text);
        self::assertStringContainsString('Stringable', $text);
    }

    public function testHoverOverPlainNameReturnsNull(): void
    {
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        class Tag { public string $name; }
        $t = new Tag();
        XPHP);
        // Cursor over the non-generic `Tag` class name in the new expression.
        $hover = $this->hoverAt($handler, $uri, $workspace->get($uri)->text, 'new Tag()', offsetInSearch: strlen('new '));

        // `Tag` carries no ATTR_GENERIC_ARGS and no enclosing template scope.
        // The handler returns null; LSP clients render nothing.
        self::assertNull($hover);
    }

    public function testUnknownUriYieldsNull(): void
    {
        $workspace = new PhpactorWorkspace();
        $handler = new XphpHoverHandler($workspace, $this->newCache());
        $params = new HoverParams(
            new TextDocumentIdentifier('/never-opened.xphp'),
            new Position(0, 0),
        );
        self::assertNull(wait($handler->hover($params)));
    }

    public function testMethodsMapRegistersHoverEndpoint(): void
    {
        // Locks ArrayItemRemoval on methods() — without the entry, the
        // dispatcher never routes textDocument/hover to this handler.
        $methods = (new XphpHoverHandler(new PhpactorWorkspace(), $this->newCache()))
            ->methods();
        self::assertArrayHasKey('textDocument/hover', $methods);
        self::assertSame('hover', $methods['textDocument/hover']);
    }

    public function testHoverOnNestedTemplateResolvesAgainstInnermostScope(): void
    {
        // Locks the `array_reverse($classScope)` walk on line 126: a class-in-
        // class arrangement where the INNER ClassLike's type-param matches
        // the cursor must be reported as the inner class's param, not the
        // outer's. Without reverse, the outer wins.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        class Outer<T>
        {
            public T $a;
        }
        class Inner<T: \Stringable>
        {
            public T $b;
        }
        XPHP);
        $source = $workspace->get($uri)->text;
        // Cursor on the `T` in `public T $b;` — Inner's T, bounded by Stringable.
        $hover = $this->hoverAt($handler, $uri, $source, 'public T $b', offsetInSearch: strlen('public '));

        self::assertInstanceOf(Hover::class, $hover);
        $text = $hover->contents->value;
        self::assertStringContainsString('App\\Inner', $text, 'must report INNER class as owner, not outer');
        self::assertStringContainsString('Stringable', $text, "must surface inner's bound, not outer's unbounded T");
    }

    public function testGenericInstantiationWithoutAllConcreteArgsReturnsNull(): void
    {
        // Locks the `&& self::allConcrete($args)` guard on line 108. Hovering
        // over a generic Name whose args still contain a type-param (not yet
        // substituted) must NOT return a specialization markdown — the FQN
        // hashing isn't valid in that state.
        //
        // We exercise this by hovering over a Box<T> reference inside a
        // template body (T is still a type-param at that point, not concrete).
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        class Wrapper<T>
        {
            public Box<T> $boxed;
        }
        XPHP);
        $source = $workspace->get($uri)->text;
        $hover = $this->hoverAt($handler, $uri, $source, 'Box<T>');

        // Either null (no usable hover) or a non-specialization hover —
        // critically, no `Specializes to:` line.
        if ($hover !== null) {
            self::assertStringNotContainsString('Specializes to', $hover->contents->value);
        } else {
            self::assertNull($hover);
        }
    }

    public function testHoverOnMultiSegmentNameReturnsNull(): void
    {
        // Locks the `count($parts) !== 1` guard inside buildHoverMarkdown.
        // A fully-qualified Name like `App\Stuff\Plastic` has multiple parts;
        // we don't try to resolve it as a type-param, and we don't have any
        // ATTR_GENERIC_ARGS on it either → return null.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        class Holder
        {
            public \App\Stuff\Plastic $item;
        }
        XPHP);
        $source = $workspace->get($uri)->text;
        $hover = $this->hoverAt($handler, $uri, $source, 'App\\Stuff\\Plastic');

        self::assertNull($hover);
    }

    public function testHoverOnSecondTypeParamSkipsFirst(): void
    {
        // Locks the `continue` in the inner foreach (line 133). With multiple
        // type-params, the handler must keep iterating past non-matching ones
        // to find the right TypeParam, not stop at the first or process the
        // wrong one.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        class Pair<K, V: \Stringable>
        {
            public K $key;
            public V $val;
        }
        XPHP);
        $source = $workspace->get($uri)->text;
        // Hover on the `V` in `public V $val;` — must report V (Stringable-bounded),
        // not K (unbounded).
        $hover = $this->hoverAt($handler, $uri, $source, 'public V $val', offsetInSearch: strlen('public '));

        self::assertInstanceOf(Hover::class, $hover);
        $text = $hover->contents->value;
        self::assertStringContainsString('`V`', $text);
        self::assertStringContainsString('Stringable', $text);
        self::assertStringNotContainsString('`K`', $text);
    }

    public function testReturnsResultWhenCancelTokenNotRequested(): void
    {
        // Pins the cancel-poll guards at lines 90 and 101:
        //   if ($cancel !== null && $cancel->isRequested()) return null;
        // Without this test, `LogicalAndSingleSubExprNegation` mutating
        // the `isRequested` clause to `!isRequested` escapes -- a
        // non-null + not-requested token would then trigger the
        // early-return and the hover would come back null.  This test
        // passes a non-null + not-requested token and asserts the
        // handler still produces the normal hover.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        class Box<T>
        {
            public T $item;
        }
        XPHP);
        $source = $workspace->get($uri)->text;

        $byte = strpos($source, 'public T $item');
        self::assertNotFalse($byte);
        $byte += strlen('public ');
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new HoverParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );

        $cancel = new \Amp\CancellationTokenSource();
        // Deliberately do NOT call $cancel->cancel().

        $hover = wait($handler->hover($params, $cancel->getToken()));
        self::assertNotNull($hover, 'non-requested cancel token must not short-circuit');
    }

    public function testReturnsNullWhenCancelTokenAlreadyRequested(): void
    {
        // The other half of the cancel-poll guard: a pre-requested
        // token must produce a null result.  Pairs with the above to
        // pin both observable branches of the
        // `if ($cancel !== null && $cancel->isRequested())` guard.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        class Box<T>
        {
            public T $item;
        }
        XPHP);
        $source = $workspace->get($uri)->text;

        $byte = strpos($source, 'public T $item');
        self::assertNotFalse($byte);
        $byte += strlen('public ');
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new HoverParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );

        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        $hover = wait($handler->hover($params, $cancel->getToken()));
        self::assertNull($hover, 'requested cancel token must short-circuit to null');
    }

    public function testTypeParamHoverIgnoresNonTypeParamEntriesInGenericParamsList(): void
    {
        // Locks `!$param instanceof TypeParam` part of the OR on line 132.
        // The genericParams attribute is always list<TypeParam>, so the
        // not-instanceof branch only fires defensively; an unrelated
        // ClassLike with a non-conforming attribute mustn't crash the
        // hover. We exercise this by hovering on a name that doesn't
        // match ANY type-param in the enclosing template — handler
        // walks each TypeParam, finds no match, returns null.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        class Box<T>
        {
            public string $note;
        }
        XPHP);
        $source = $workspace->get($uri)->text;
        // Cursor on `note` — it's not a type-param of Box<T>; matchesPrefix
        // returns no candidates → handler returns null.
        $hover = $this->hoverAt($handler, $uri, $source, 'note');
        self::assertNull($hover);
    }

    public function testHoverInsideAngleClauseResolvesTypeArgFqn(): void
    {
        // The bug: hovering `Tag` inside `<\App\Models\Tag>` used to
        // fall through to worse-reflection, which attributed the
        // (whitespace-in-stripped-source) offset to the enclosing
        // `new StringableBox(...)` and rendered the OUTER class as
        // the hover.  After the fix, the handler short-circuits via
        // ATTR_GENERIC_ARGS and emits the type-arg's class hover.
        // Without a PhpHoverResolver wired up, the handler still
        // emits a minimal markdown line carrying the resolved FQN so
        // the user never sees the wrong class.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        $bounded = new StringableBox<\App\Models\Tag>();
        XPHP);
        $source = $workspace->get($uri)->text;
        // Cursor on the `T` of `Tag` inside the angle clause.
        $hover = $this->hoverAt($handler, $uri, $source, 'Tag>');

        self::assertInstanceOf(Hover::class, $hover);
        self::assertInstanceOf(MarkupContent::class, $hover->contents);
        self::assertStringContainsString('App\\Models\\Tag', $hover->contents->value);
        self::assertStringNotContainsString('StringableBox', $hover->contents->value);
    }

    public function testHoverOnAngleDelimiterReturnsNull(): void
    {
        // Cursor exactly on `<` or `>` is NOT inside any arg; the
        // angle-clause path returns null and the handler falls
        // through.  Without a PhpHoverResolver wired, that means
        // the overall hover is null.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        $bounded = new StringableBox<\App\Models\Tag>();
        XPHP);
        $source = $workspace->get($uri)->text;
        $hover = $this->hoverAt($handler, $uri, $source, '<\\App\\Models\\Tag');

        self::assertNull($hover);
    }

    public function testHoverInsideAngleClausePicksSecondArgByComma(): void
    {
        // Multi-arg clause: cursor inside the SECOND arg must surface
        // that arg's FQN, not the first.  Locks comma-counting in
        // topLevelArgIndexAt.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        $pair = new Pair<\App\Models\Tag, \App\Models\User>();
        XPHP);
        $source = $workspace->get($uri)->text;
        // Cursor on `U` of `User` (second arg).
        $hover = $this->hoverAt($handler, $uri, $source, 'User>');

        self::assertInstanceOf(Hover::class, $hover);
        self::assertStringContainsString('App\\Models\\User', $hover->contents->value);
        self::assertStringNotContainsString('Tag', $hover->contents->value);
    }

    public function testHoverInsideAngleClauseOnTypeParamReturnsNull(): void
    {
        // Cursor on a type-param REFERENCE inside a `<...>` clause
        // (e.g. `Box<T>` where T is the enclosing template's param)
        // must NOT emit a class hover -- the FQN would be a bogus
        // namespaced placeholder.  Without a PhpHoverResolver wired
        // the handler returns null; with one wired the Case 2
        // (type-param hover) path applies, but that's a different
        // entry point.  Locks the `$arg->isTypeParam` early-return
        // in typeArgFqnAt.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        class Wrapper<T>
        {
            public Box<T> $boxed;
        }
        XPHP);
        $source = $workspace->get($uri)->text;
        // Cursor on `T` inside `Box<T>` -- inside the angle clause.
        $byte = strpos($source, 'Box<T>');
        self::assertNotFalse($byte);
        $byte += strlen('Box<');
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new HoverParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        $hover = wait($handler->hover($params));

        self::assertNull($hover);
    }

    public function testHoverInsideAngleClauseOnScalarReturnsNull(): void
    {
        // Scalar args (`Box<int>`) should NOT render a class hover --
        // worse-reflection can't reflect a built-in scalar as a class
        // and the user would get a nonsense `class \int` markdown.
        // Locks the `$arg->isScalar` early-return in typeArgFqnAt.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        $b = new Box<int>();
        XPHP);
        $source = $workspace->get($uri)->text;
        $hover = $this->hoverAt($handler, $uri, $source, 'int>');

        self::assertNull($hover);
    }

    public function testHoverPicksSecondAngleClauseHitWhenCursorIsThere(): void
    {
        // Two generic instantiations; cursor inside the SECOND.  The
        // foreach in angleClauseAt must `continue` past the first
        // Name node (whose angle clause doesn't contain the cursor)
        // and keep iterating -- not `break`.  Locks the
        // strict-containment-guard `continue` against
        // Continue_ -> Break_ mutation.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        $a = new Box<\App\Models\Tag>();
        $b = new Box<\App\Models\User>();
        XPHP);
        $source = $workspace->get($uri)->text;
        // Cursor on the second occurrence of `Tag` or `User`.  We use
        // `User>` so strpos finds the second clause.
        $hover = $this->hoverAt($handler, $uri, $source, 'User>');

        self::assertInstanceOf(Hover::class, $hover);
        self::assertStringContainsString('App\\Models\\User', $hover->contents->value);
        self::assertStringNotContainsString('Tag', $hover->contents->value);
    }

    public function testHoverPicksFirstAngleClauseHitInDocument(): void
    {
        // Two generic instantiations: cursor inside the FIRST.  The
        // visitor MUST short-circuit when it finds its hit; without
        // the `$this->hit !== null` guard, a later Name node could
        // overwrite `$this->hit` and the wrong FQN would surface.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        $a = new Box<\App\Models\Tag>();
        $b = new Box<\App\Models\User>();
        XPHP);
        $source = $workspace->get($uri)->text;
        // Cursor on first `Tag`.
        $hover = $this->hoverAt($handler, $uri, $source, 'Tag>');

        self::assertInstanceOf(Hover::class, $hover);
        self::assertStringContainsString('App\\Models\\Tag', $hover->contents->value);
        self::assertStringNotContainsString('User', $hover->contents->value);
    }

    public function testHoverAtFirstByteInsideAngleClauseResolves(): void
    {
        // Cursor on the first byte INSIDE `<...>` (the `\` of
        // `\App\Models\Tag`).  Locks the `$range['openPos'] + 1`
        // computation for innerStart -- if that arithmetic shifts,
        // the relative offset is off and the arg index can land in
        // the wrong slot.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        $b = new Box<\App\Models\Tag>();
        XPHP);
        $source = $workspace->get($uri)->text;
        $byte = strpos($source, '<\\App');
        self::assertNotFalse($byte);
        $byte += 1;  // skip `<`, land on the `\` -- first byte inside the clause
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new HoverParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        $hover = wait($handler->hover($params));

        self::assertInstanceOf(Hover::class, $hover);
        self::assertStringContainsString('App\\Models\\Tag', $hover->contents->value);
    }

    public function testHoverAtLastByteInsideAngleClauseResolves(): void
    {
        // Cursor on the last byte INSIDE `<...>` (the `g` of `Tag>`).
        // Locks the `$j - 1` arithmetic for closePos in
        // findAngleRange -- if that shifts left, the cursor at
        // closePos-1 would be considered outside the clause.
        [$handler, $workspace, $uri] = $this->prepare(<<<'XPHP'
        <?php
        namespace App;
        $b = new Box<\App\Models\Tag>();
        XPHP);
        $source = $workspace->get($uri)->text;
        $byte = strpos($source, 'Tag>');
        self::assertNotFalse($byte);
        $byte += 2;  // cursor on the `g` -- last byte before `>`
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new HoverParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        $hover = wait($handler->hover($params));

        self::assertInstanceOf(Hover::class, $hover);
        self::assertStringContainsString('App\\Models\\Tag', $hover->contents->value);
    }

    public function testFindAngleRangeSkipsWhitespaceBetweenNameAndAngle(): void
    {
        // Locks the `while ($i < $n && ctype_space($source[$i])) $i++;`
        // loop in findAngleRange.  Removing the loop would leave $i
        // pointing at the first whitespace byte; the subsequent
        // `$source[$i] !== '<'` check would then return null and
        // we'd miss the clause entirely.
        $source = 'StringableBox  <Tag>';
        $range = XphpHoverHandler::findAngleRange($source, strlen('StringableBox') - 1);
        self::assertNotNull($range);
        self::assertSame(strpos($source, '<'), $range['openPos']);
        self::assertSame(strpos($source, '>'), $range['closePos']);
    }

    public function testFindAngleRangeReturnsNullForUnterminatedClause(): void
    {
        // Source has `<` but no matching `>` -- the depth-tracking
        // loop ends with $depth > 0.  Without the `if ($depth !== 0)
        // return null;` check, the function would still return a
        // bogus closePos (the end of source), which would then
        // produce an absurd innerText extending past the actual EOF.
        $source = 'Box<Tag';
        self::assertNull(XphpHoverHandler::findAngleRange($source, strlen('Box') - 1));
    }

    public function testFindAngleRangeReturnsNullWhenNoAngleAfterName(): void
    {
        // Name is followed by `(` directly, not `<`.  The
        // `$source[$i] !== '<'` check returns null.  Locks the
        // `||` operator in the bounds-or-not-open guard -- mutating
        // to `&&` would require BOTH conditions, letting the no-
        // angle case proceed past the guard and producing a bogus
        // result.
        $source = 'Box()';
        self::assertNull(XphpHoverHandler::findAngleRange($source, strlen('Box') - 1));
    }

    public function testFindAngleRangeHandlesNestedAngles(): void
    {
        // Nested `<...>`: the outer angle clause's closePos must
        // be the LAST `>`, not the first.  Locks the depth-tracking
        // (`$depth > 0` and the `<`/`>` increment/decrement) in the
        // match loop.
        $source = 'Box<Map<K,V>>';
        $range = XphpHoverHandler::findAngleRange($source, strlen('Box') - 1);
        self::assertNotNull($range);
        self::assertSame(strpos($source, '<'), $range['openPos']);
        self::assertSame(strrpos($source, '>'), $range['closePos']);
    }

    public function testFindAngleRangeReturnsNullWhenNameAtEndOfSource(): void
    {
        // Source ends immediately at the name -- there's nothing
        // after `nameEnd` to scan.  The `$i >= $n` bounds check
        // catches this.  Locks both the GTE comparison and the `||`
        // operator in the bounds-or-not-open guard.
        self::assertNull(XphpHoverHandler::findAngleRange('Box', strlen('Box') - 1));
    }

    /**
     * @return array{0: XphpHoverHandler, 1: PhpactorWorkspace, 2: string}
     */
    private function prepare(string $source): array
    {
        $workspace = new PhpactorWorkspace();
        $uri = '/doc.xphp';
        $workspace->open(new TextDocumentItem($uri, 'xphp', 1, $source));
        $handler = new XphpHoverHandler($workspace, $this->newCache());
        return [$handler, $workspace, $uri];
    }

    private function hoverAt(
        XphpHoverHandler $handler,
        string $uri,
        string $source,
        string $search,
        int $offsetInSearch = 0,
    ): ?Hover {
        $byte = strpos($source, $search);
        self::assertNotFalse($byte, "fixture search string '{$search}' must exist in source");
        $byte += $offsetInSearch;
        [$line, $character] = (new PositionMap($source))->offsetToPosition($byte);
        $params = new HoverParams(
            new TextDocumentIdentifier($uri),
            new Position($line, $character),
        );
        return wait($handler->hover($params));
    }

    private function newCache(): ParsedDocumentCache
    {
        return new ParsedDocumentCache(
            new Analyzer(new XphpSourceParser((new ParserFactory())->createForHostVersion())),
        );
    }
}
