<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use Amp\CancellationToken;
use Amp\Promise;
use Amp\Success;
use Phpactor\LanguageServer\Core\Handler\CanRegisterCapabilities;
use Phpactor\LanguageServer\Core\Handler\Handler;
use Phpactor\LanguageServerProtocol\Location;
use Phpactor\LanguageServerProtocol\ServerCapabilities;
use Phpactor\LanguageServerProtocol\TypeDefinitionParams;
use XPHP\Lsp\Resolver\PhpDefinitionResolver;

/**
 * `textDocument/typeDefinition` handler.
 *
 * "Go To Type Declaration" jumps to the class definition of the
 * cursor's inferred type rather than the cursor's own declaration
 * site.  Example:
 *
 *   $user = new User();
 *   $user->name;
 *      ^ cursor here
 *
 *   `definition` -> jumps to `$user = new User()`
 *   `typeDefinition` -> jumps to `class User`
 *
 * Reuses `PhpDefinitionResolver::resolveType` so we get worse-
 * reflection's type inference + the same locator chain as ordinary
 * GTD (workspace doc, filesystem walk, phpstorm-stubs).
 *
 * Server capability is advertised as bool `true` for the same
 * reason the other handlers do: phpactor's JSON serializer
 * null-strips empty options objects to `[]`, which IntelliJ
 * rejects.
 *
 * Available since IntelliJ Platform 2024.3.1.
 */
final class XphpTypeDefinitionHandler implements Handler, CanRegisterCapabilities
{
    public function __construct(
        private readonly PhpDefinitionResolver $resolver,
    ) {
    }

    public function methods(): array
    {
        return [
            'textDocument/typeDefinition' => 'typeDefinition',
        ];
    }

    public function registerCapabiltiies(ServerCapabilities $capabilities): void
    {
        $capabilities->typeDefinitionProvider = true;
    }

    /**
     * @return Promise<Location|null>
     */
    public function typeDefinition(TypeDefinitionParams $params, ?CancellationToken $cancel = null): Promise
    {
        if ($cancel !== null && $cancel->isRequested()) {
            return new Success(null);
        }
        $location = $this->resolver->resolveType(
            $params->textDocument->uri,
            $params->position->line,
            $params->position->character,
            $cancel,
        );
        return new Success($location);
    }
}
