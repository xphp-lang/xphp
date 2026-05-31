<?php

declare(strict_types=1);

namespace XPHP\Lsp\Test\Dispatcher;

use Phpactor\LanguageServer\Core\Dispatcher\Exception\CouldNotResolveArguments;
use Phpactor\LanguageServer\Core\Rpc\NotificationMessage;
use Phpactor\LanguageServer\Core\Rpc\RequestMessage;
use Phpactor\LanguageServerProtocol\CodeAction;
use Phpactor\LanguageServerProtocol\CompletionItem;
use Phpactor\LanguageServerProtocol\CompletionItemKind;
use Phpactor\LanguageServerProtocol\HoverParams;
use PHPUnit\Framework\TestCase;
use XPHP\Lsp\Dispatcher\LspObjectArgumentResolver;

final class LspObjectArgumentResolverTest extends TestCase
{
    public function testDeserialisesCompletionItem(): void
    {
        $resolver = new LspObjectArgumentResolver();
        $handler = new class {
            public function resolve(CompletionItem $item): void
            {
            }
        };
        $request = new RequestMessage(
            id: 1,
            method: 'completionItem/resolve',
            params: [
                'label' => 'User',
                'kind' => CompletionItemKind::CLASS_,
                'data' => ['kind' => 'class', 'fqn' => 'App\\User'],
            ],
        );

        $args = $resolver->resolveArguments($handler, 'resolve', $request);

        self::assertCount(1, $args);
        self::assertInstanceOf(CompletionItem::class, $args[0]);
        self::assertSame('User', $args[0]->label);
        self::assertSame(['kind' => 'class', 'fqn' => 'App\\User'], $args[0]->data);
    }

    public function testDeserialisesCodeAction(): void
    {
        $resolver = new LspObjectArgumentResolver();
        $handler = new class {
            public function resolve(CodeAction $action): void
            {
            }
        };
        $request = new RequestMessage(
            id: 1,
            method: 'codeAction/resolve',
            params: ['title' => 'Quick fix'],
        );

        $args = $resolver->resolveArguments($handler, 'resolve', $request);

        self::assertCount(1, $args);
        self::assertInstanceOf(CodeAction::class, $args[0]);
        self::assertSame('Quick fix', $args[0]->title);
    }

    public function testThrowsCouldNotResolveForUnsupportedFirstParameterType(): void
    {
        $resolver = new LspObjectArgumentResolver();
        $handler = new class {
            public function hover(HoverParams $params): void
            {
            }
        };
        $request = new RequestMessage(
            id: 1,
            method: 'textDocument/hover',
            params: [],
        );

        $this->expectException(CouldNotResolveArguments::class);
        $resolver->resolveArguments($handler, 'hover', $request);
    }

    public function testThrowsCouldNotResolveForUntypedFirstParameter(): void
    {
        $resolver = new LspObjectArgumentResolver();
        $handler = new class {
            public function resolve($item): void
            {
            }
        };
        $request = new RequestMessage(id: 1, method: 'x', params: []);

        $this->expectException(CouldNotResolveArguments::class);
        $resolver->resolveArguments($handler, 'resolve', $request);
    }

    public function testThrowsCouldNotResolveForNonRequestMessages(): void
    {
        $resolver = new LspObjectArgumentResolver();
        $handler = new class {
            public function resolve(CompletionItem $item): void
            {
            }
        };
        // Custom non-Request/Notification Message subclass.  Use a
        // mock since the Message interface is sealed in practice.
        $fakeMessage = $this->getMockBuilder(\Phpactor\LanguageServer\Core\Rpc\Message::class)
            ->getMock();

        $this->expectException(CouldNotResolveArguments::class);
        $resolver->resolveArguments($handler, 'resolve', $fakeMessage);
    }

    public function testAcceptsNotificationMessageShape(): void
    {
        $resolver = new LspObjectArgumentResolver();
        $handler = new class {
            public function resolve(CompletionItem $item): void
            {
            }
        };
        $request = new NotificationMessage(
            method: 'completionItem/resolve',
            params: ['label' => 'X'],
        );

        $args = $resolver->resolveArguments($handler, 'resolve', $request);
        self::assertInstanceOf(CompletionItem::class, $args[0]);
    }
}
