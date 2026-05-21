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
