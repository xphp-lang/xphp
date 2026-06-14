<?php

declare(strict_types=1);

/**
 * Runtime verify for `generic_exception_catch`: a `catch` clause typed on a
 * generic specialization (`HttpError<NotFound>` vs `HttpError<Forbidden>`)
 * discriminates on the concrete monomorphized class, so the right arm fires.
 *
 * Driver contract: `$fixture` (CompiledFixture) in scope, autoload registered
 * for `App\GenericExceptionCatch\` + the generated namespace.
 */

use App\GenericExceptionCatch\Client;
use PHPUnit\Framework\Assert;
use XPHP\Transpiler\Monomorphize\Registry;
use XPHP\Transpiler\Monomorphize\TypeRef;

$client = new Client();

// Generic type discriminates: the Forbidden throw must skip the textually
// FIRST `HttpError<NotFound>` arm and land on `HttpError<Forbidden>`. This is
// the property that would silently break if catch types stopped being
// rewritten to their distinct specialized FQNs.
Assert::assertSame('forbidden:api key revoked', $client->classify('forbidden'));
Assert::assertSame('not-found:missing', $client->classify('notfound'));

// Bare `catch (HttpError $e)` catches any specialization via the marker
// interface. The caught object is the concrete Forbidden specialization.
$forbiddenFqn = Registry::generatedFqn(
    'App\\GenericExceptionCatch\\Errors\\HttpError',
    [new TypeRef('App\\GenericExceptionCatch\\Models\\Forbidden')],
);
Assert::assertSame($forbiddenFqn, $client->catchAny('forbidden'));

// A union of two specializations matches either thrown error.
Assert::assertSame('union:missing', $client->catchUnion('notfound'));
Assert::assertSame('union:api key revoked', $client->catchUnion('forbidden'));

// The specialization is a genuine Throwable subtype (so it is catchable at all)
// AND implements the original generic name as a marker interface (so the bare
// catch-all arm above can match it).
Assert::assertTrue(
    is_subclass_of($forbiddenFqn, \RuntimeException::class),
    'specialized exception must remain a RuntimeException subtype',
);
Assert::assertTrue(
    is_subclass_of($forbiddenFqn, 'App\\GenericExceptionCatch\\Errors\\HttpError'),
    'specialized exception must implement the HttpError marker interface',
);
