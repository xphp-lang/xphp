<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Handler;

use PhpParser\ParserFactory;
use Phpactor\LanguageServer\Core\Workspace\Workspace as PhpactorWorkspace;
use Phpactor\LanguageServerProtocol\InlayHint;
use Phpactor\LanguageServerProtocol\InlayHintKind;
use Phpactor\LanguageServerProtocol\InlayHintParams;
use Phpactor\LanguageServerProtocol\Position;
use Phpactor\LanguageServerProtocol\Range;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TextDocumentIdentifier;
use Phpactor\LanguageServerProtocol\TextDocumentItem;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Analyzer\Analyzer;
use XPHP\Lsp\Analyzer\ParsedDocumentCache;
use XPHP\Lsp\Handler\XphpInlayHintHandler;
use XPHP\Lsp\Reflection\FqnIndex;
use XPHP\Lsp\Resolver\CompositeClassLikeLookup;
use XPHP\Lsp\Resolver\FilesystemClassLikeLookup;
use XPHP\Lsp\Resolver\GenericResolver;
use XPHP\Lsp\Resolver\WorkspaceClassLikeLookup;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

use function Amp\Promise\wait;

final class XphpInlayHintHandlerTest extends TestCase
{
    public function testEmitsSubstitutedReturnTypeForGenericMethodCall(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/Collection.xphp',
            'xphp',
            1,
            <<<'XPHP'
            <?php
            namespace App\Containers;
            class Collection<T>
            {
                public function first(): ?T { return null; }
            }
            XPHP,
        ));
        $workspace->open(new TextDocumentItem(
            '/User.xphp',
            'xphp',
            1,
            "<?php\nnamespace App\\Models;\nclass User {}\n",
        ));
        $workspace->open(new TextDocumentItem(
            '/Use.xphp',
            'xphp',
            1,
            "<?php\nuse App\\Containers\\Collection;\nuse App\\Models\\User;\n\$users = new Collection<User>();\n\$first = \$users->first();\n",
        ));

        $hints = $this->hintsFor($workspace, '/Use.xphp');

        // Two assignments in Use.xphp: $users (RHS = New_, no
        // substituted method return → no hint) and $first (RHS =
        // method call → hint).
        self::assertCount(1, $hints);
        self::assertSame(InlayHintKind::TYPE, $hints[0]->kind);
        // Exact label assertion pins the `': ' . returnType` concat
        // (kills Concat / ConcatOperandRemoval mutants on that join).
        self::assertSame(': ?App\\Models\\User', $hints[0]->label);
        self::assertTrue($hints[0]->paddingLeft);
    }

    public function testEmitsNoHintForNonGenericMethodCall(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/Greeter.xphp',
            'xphp',
            1,
            "<?php\nnamespace App;\nclass Greeter { public function hi(): string { return 'hi'; } }\n",
        ));
        $workspace->open(new TextDocumentItem(
            '/Use.xphp',
            'xphp',
            1,
            "<?php\nuse App\\Greeter;\n\$g = new Greeter();\n\$x = \$g->hi();\n",
        ));

        $hints = $this->hintsFor($workspace, '/Use.xphp');

        // No generic substitution → resolveMethodCallSubstitutionAt
        // returns null → no hint.
        self::assertCount(0, $hints);
    }

    public function testEmitsNoHintForUnknownDocument(): void
    {
        $workspace = new PhpactorWorkspace();
        $hints = $this->hintsFor($workspace, '/never-opened.xphp');

        self::assertSame([], $hints);
    }

    public function testReturnsEmptyArrayWhenCancelTokenAlreadyRequested(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem(
            '/Use.xphp',
            'xphp',
            1,
            "<?php\n\$x = 1;\n",
        ));

        $handler = $this->handler($workspace);
        $params = new InlayHintParams(
            new TextDocumentIdentifier('/Use.xphp'),
            new Range(new Position(0, 0), new Position(99, 0)),
        );
        $cancel = new \Amp\CancellationTokenSource();
        $cancel->cancel();

        self::assertSame([], wait($handler->inlayHint($params, $cancel->getToken())));
    }

    public function testEmitsNoHintForUnparseableSource(): void
    {
        $workspace = new PhpactorWorkspace();
        $workspace->open(new TextDocumentItem('/Bad.xphp', 'xphp', 1, "<?php\n{{{ garbage"));

        $hints = $this->hintsFor($workspace, '/Bad.xphp');

        self::assertSame([], $hints);
    }

    public function testAdvertisesInlayHintProviderCapability(): void
    {
        $caps = new ServerCapabilities();
        $this->handler(new PhpactorWorkspace())->registerCapabiltiies($caps);

        self::assertTrue($caps->inlayHintProvider);
    }

    public function testMethodsMapAdvertisesInlayHintEndpoint(): void
    {
        self::assertArrayHasKey(
            'textDocument/inlayHint',
            $this->handler(new PhpactorWorkspace())->methods(),
        );
    }

    /**
     * @return list<InlayHint>
     */
    private function hintsFor(PhpactorWorkspace $workspace, string $uri): array
    {
        $handler = $this->handler($workspace);
        $params = new InlayHintParams(
            new TextDocumentIdentifier($uri),
            new Range(new Position(0, 0), new Position(999, 0)),
        );
        $result = wait($handler->inlayHint($params));
        self::assertIsArray($result);
        return $result;
    }

    private function handler(PhpactorWorkspace $workspace): XphpInlayHintHandler
    {
        $parser = new XphpSourceParser((new ParserFactory())->createForHostVersion());
        $cache = new ParsedDocumentCache(new Analyzer($parser));
        $fqnIndex = new FqnIndex($workspace, $cache, $parser, '');
        $classLikeLookup = new CompositeClassLikeLookup(
            new WorkspaceClassLikeLookup($workspace, $cache),
            new FilesystemClassLikeLookup($fqnIndex),
        );
        $generic = new GenericResolver($workspace, $cache, $classLikeLookup, $parser, $fqnIndex);
        return new XphpInlayHintHandler($workspace, $cache, $generic);
    }
}
