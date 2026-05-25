<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use RuntimeException;

/**
 * Thrown by `RenameProvider` when the requested new name isn't a valid
 * PHP identifier (only letters/underscore for the leading byte; letters,
 * digits, underscore thereafter).  The handler converts this into an
 * LSP error response with a user-facing message.
 */
final class InvalidRenameNameException extends RuntimeException
{
}
