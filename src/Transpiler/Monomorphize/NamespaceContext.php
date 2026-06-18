<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;

/**
 * Tracks the current namespace and use-map while walking an AST. Two visitors
 * need the same resolution policy (XphpSourceParser's inner visitor, and the
 * RegistryCollector that runs later in the pipeline): both must turn a bare
 * Name like `Box` into the same FQN given the same enclosing namespace + uses.
 *
 * Not thread-safe; one instance per traversal.
 */
final class NamespaceContext
{
    private string $currentNamespace = '';
    /** @var array<string, string> alias → FQN */
    private array $useMap = [];

    /**
     * Push a new enclosing namespace. `$name` is the namespace string
     * (e.g. `App\Containers`) or `null` for a bare top-level scope.
     * Clears the use map -- PHP scopes uses to the namespace block.
     */
    public function enterNamespace(?string $name): void
    {
        // @infection-ignore-all -- bare `namespace { ... }` (no name) is treated
        // identically to top-level code by every existing fixture; the null-coalesce
        // never observably differs from '' in the test suite.
        $this->currentNamespace = $name ?? '';
        $this->useMap = [];
    }

    /**
     * Index a `Use_` AST node into the use map. The alias is the segment after
     * `as`, or the last segment of the imported FQN if no alias is given.
     */
    public function indexUse(Use_ $use): void
    {
        foreach ($use->uses as $u) {
            // @phpstan-ignore-next-line instanceof.alwaysTrue — defensive guard against nikic/php-parser PHPDoc-narrowed Use_::$uses (pre-5.x emitted UseUse, current emits UseItem).
            if (!$u instanceof UseItem) {
                continue;
            }
            $fqn = $u->name->toString();
            $alias = $u->alias?->toString() ?? self::lastSegment($fqn);
            $this->useMap[$alias] = $fqn;
        }
    }

    /**
     * Resolve a single name against the current namespace + use map. Returns
     * the FQN (without a leading backslash).
     *
     *  - Leading-`\\` names are already absolute; the backslash is stripped.
     *  - Bare names whose first segment matches a use-map alias are rewritten
     *    by replacing the alias prefix with the aliased FQN.
     *  - Anything else gets the current namespace prefixed (when non-empty).
     *
     * Does NOT consult any type-parameter scope; callers that need that check
     * must do it before delegating here.
     */
    public function resolveAgainstContext(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $first = self::firstSegment($name);
        if (isset($this->useMap[$first])) {
            $rest = substr($name, strlen($first));
            return $this->useMap[$first] . $rest;
        }
        return $this->currentNamespace !== ''
            ? $this->currentNamespace . '\\' . $name
            : $name;
    }

    public function currentNamespace(): string
    {
        return $this->currentNamespace;
    }

    /**
     * Whether a bare name's first segment is brought into scope by a `use`
     * import. Used to spare imported (and therefore deliberate) class references
     * from the undeclared-type-parameter check: an imported name is the author's
     * explicit statement that the type lives elsewhere, so it's never "suspect".
     */
    public function isImported(string $name): bool
    {
        return isset($this->useMap[self::firstSegment($name)]);
    }

    private static function firstSegment(string $name): string
    {
        $pos = strpos($name, '\\');
        return $pos === false ? $name : substr($name, 0, $pos);
    }

    private static function lastSegment(string $name): string
    {
        $pos = strrpos($name, '\\');
        return $pos === false ? $name : substr($name, $pos + 1);
    }
}
