<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use XPHP\Lsp\Reflection\FqnIndex;

/**
 * Recognises type references like `App\Containers\T` as the generic placeholder
 * `T` declared on `App\Containers\Collection<T>`, so the LSP can render them
 * sensibly in hover tooltips and completion details.
 *
 * Background: xphp's compile pipeline strips `<T>` clauses to whitespace before
 * worse-reflection sees the source.  After strip, `function first(): ?T { ... }`
 * leaves `T` standing as a normal class reference; worse-reflection resolves it
 * against the surrounding namespace (`App\Containers`) -> `?App\Containers\T`.
 * For the compiler that's fine -- monomorphization replaces these references
 * at instantiation time -- but the LSP shows the unresolved form to the user.
 *
 * Phase 0.5: the registry now consumes `FqnIndex` so it sees both open-doc
 * AND filesystem-only generic classes.  Before this migration the registry
 * walked the workspace itself and missed any class whose declaration wasn't
 * currently open in the editor -- a real-world hit recorded in
 * xphp-20260524-231944-855.log where `?App\Containers\T` leaked into hover
 * because Collection.xphp wasn't open.
 *
 * The pair set ((namespace, paramName) tuples) is built once per (workspace
 * state, filesystem state) and cached.  Filesystem-side data persists for
 * the LSP session (the index doesn't watch for new files); open-doc data
 * picks up changes through `ParsedDocumentCache`'s version keying.
 */
final class GenericParamRegistry
{
    /**
     * @var array<string, true>|null  cache of "Namespace|ParamName" keys; null until first build.
     */
    private ?array $pairs = null;

    public function __construct(
        private readonly FqnIndex $fqnIndex,
    ) {
    }

    /**
     * Rewrite a type string so generic-placeholder references display
     * without their namespace prefix.  Idempotent and side-effect-free.
     *
     * `?App\Containers\T`           ->  `?T`
     * `App\Containers\T`            ->  `T`
     * `App\Models\User`             ->  `App\Models\User`  (User isn't a known placeholder)
     * `array<App\Containers\T, V>`  ->  `array<T, V>`      (when both are placeholders)
     *
     * Operates on each `\`-qualified name inside the input independently;
     * non-name characters (`?`, `<`, `>`, `,`, whitespace) act as
     * delimiters.  Match is case-sensitive (PHP class names are
     * case-insensitive in practice, but in xphp source the convention is
     * single-uppercase placeholders, and we never want to mangle a
     * non-placeholder by accident).
     */
    public function prettify(string $type): string
    {
        if ($type === '' || $type === '<missing>') {
            return $type;
        }
        $pairs = $this->pairs();
        if ($pairs === []) {
            return $type;
        }
        // Match any sequence of `\`-separated identifier chars.  Identifier
        // chars match PHP's namespace grammar: letters, digits, underscore.
        return preg_replace_callback(
            '/[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/',
            function (array $m) use ($pairs): string {
                $name = $m[0];
                $sep = strrpos($name, '\\');
                if ($sep === false) {
                    return $name;
                }
                $namespace = substr($name, 0, $sep);
                $leaf = substr($name, $sep + 1);
                return isset($pairs[$namespace . '|' . $leaf]) ? $leaf : $name;
            },
            $type,
        ) ?? $type;
    }

    /**
     * @return array<string, true>  "Namespace|ParamName" -> true
     */
    private function pairs(): array
    {
        if ($this->pairs !== null) {
            return $this->pairs;
        }
        $pairs = [];
        // Class-level placeholders (Collection<T>): T inside method bodies
        // resolves to `App\Containers\T`; pair set strips back to `T`.
        foreach ($this->fqnIndex->iterGenericClasses() as $fqn => $paramNames) {
            $sep = strrpos($fqn, '\\');
            $namespace = $sep === false ? '' : substr($fqn, 0, $sep);
            foreach ($paramNames as $paramName) {
                $pairs[$namespace . '|' . $paramName] = true;
            }
        }
        // Function- and method-scope placeholders (identity<T>(...),
        // Util::identity<T>(...)): T inside the body resolves to the
        // ENCLOSING namespace's `T`, distinct from any class-level T.
        // Without this entry, hover on the function/method declaration
        // line leaks the namespace-doubled placeholder (App\Demos\T).
        foreach ($this->fqnIndex->iterGenericFunctionsAndMethods() as $fqn => $paramNames) {
            $sep = strrpos($fqn, '\\');
            $namespace = $sep === false ? '' : substr($fqn, 0, $sep);
            foreach ($paramNames as $paramName) {
                $pairs[$namespace . '|' . $paramName] = true;
            }
        }
        $this->pairs = $pairs;
        return $pairs;
    }

    /**
     * Drop the cached pair set so the next call re-walks the index.
     * Useful when tests want to add a new generic class after construction.
     * In production the cache is rebuilt naturally because FqnIndex's
     * open-doc walk uses version-keyed `ParsedDocumentCache`, and
     * filesystem-side data is session-scoped.
     */
    public function invalidate(): void
    {
        $this->pairs = null;
    }
}
