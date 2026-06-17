# Generic exceptions and catch

A generic class can extend an exception type, and `catch` clauses can
discriminate between its specializations. Because each instantiation is
its own real class at runtime, `catch (HttpError<NotFound> $e)` catches
*only* that specialization — the type system carries through to control
flow.

## Example

```php
<?php
declare(strict_types=1);

namespace App;

class HttpError<T> extends \RuntimeException {
    public function __construct(public string $detail) {
        parent::__construct($detail);
    }
}

function raise(string $which): void {
    if ($which === 'forbidden') {
        throw new HttpError::<Forbidden>('api key revoked');
    }
    throw new HttpError::<NotFound>('missing');
}

try {
    raise($which);
} catch (HttpError<NotFound> $e) {
    return 'not-found:' . $e->detail;
} catch (HttpError<Forbidden> $e) {
    return 'forbidden:' . $e->detail;
}
```

## What gets emitted

`throw new HttpError::<Forbidden>(...)` uses [turbofish](turbofish.md)
like any other instantiation, and each `catch` arm rewrites to the
specialization's generated FQN — so the arms stay distinct in the
emitted PHP:

```php
try {
    raise($which);
} catch (\XPHP\Generated\App\HttpError\T_<hash-of-NotFound> $e) {
    return 'not-found:' . $e->detail;
} catch (\XPHP\Generated\App\HttpError\T_<hash-of-Forbidden> $e) {
    return 'forbidden:' . $e->detail;
}
```

## Catching any specialization

A bare `catch (HttpError $e)` — no type arguments — catches *every*
`HttpError<...>` via the marker interface that every specialization
implements. It is left untouched in the emit:

```php
} catch (HttpError $e) {
    return $e::class; // the concrete specialized class name
}
```

## Union catch

A catch arm may union specializations; it lowers to a native PHP union
of the generated FQNs:

```php
} catch (HttpError<NotFound> | HttpError<Forbidden> $e) {
    return 'union:' . $e->detail;
}
```

## Rules

- The `catch` clause uses bare angle brackets (`HttpError<NotFound>`),
  like every other type-hint position — not the `::<>`
  [turbofish](turbofish.md), which is reserved for call sites
  (`throw new HttpError::<NotFound>(...)`).
- Arms are ordered as written; a thrown `HttpError<Forbidden>` falls
  through a preceding `HttpError<NotFound>` arm because they are
  genuinely different runtime classes.
- Bare `catch (HttpError $e)` matches any specialization via the marker
  interface; type-argumented arms match exactly one.
- Specializations are only generated for type arguments that actually
  appear in the program (`throw`, `new`, type hints). Catching a
  specialization that is never instantiated catches nothing.

## See also

- Test fixture: `test/fixture/compile/generic_exception_catch/`
- Related: [classes and interfaces](classes-and-interfaces.md),
  [turbofish](turbofish.md)
