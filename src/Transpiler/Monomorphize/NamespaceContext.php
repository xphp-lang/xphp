<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
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
    /** @var array<string, string> alias → FQN (class/namespace `use` imports) */
    private array $useMap = [];
    /** @var array<string, string> alias → FQN (`use function` imports) */
    private array $functionUseMap = [];
    /** @var array<string, string> alias → FQN (`use const` imports) */
    private array $constUseMap = [];

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
        $this->functionUseMap = [];
        $this->constUseMap = [];
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
            // The class/namespace map keeps EVERY import (including function/const,
            // as it always has) so class resolution and isImported() are unchanged.
            $this->useMap[$alias] = $fqn;
            // Additionally route `use function` / `use const` into their own maps so
            // function/const resolution honours PHP's separate symbol namespaces — a
            // class `use App\Helper;` must never capture a `helper()` call. The item's
            // own type wins when set (mixed groups), else the statement's type.
            $type = $u->type !== Use_::TYPE_UNKNOWN ? $u->type : $use->type;
            if ($type === Use_::TYPE_FUNCTION) {
                $this->functionUseMap[$alias] = $fqn;
            } elseif ($type === Use_::TYPE_CONSTANT) {
                $this->constUseMap[$alias] = $fqn;
            }
        }
    }

    /**
     * Resolve a php-parser Name used as a FREE-FUNCTION callee to its FQN,
     * honouring PHP's function-resolution rules: an unqualified name binds a
     * `use function` import first, else the current namespace (with a global
     * fallback the caller preserves by NOT rewriting when the FQN is unknown);
     * a qualified name's leading segment is a NAMESPACE, resolved via the
     * class/namespace `use` map — never the function-import map.
     */
    public function resolveFunctionName(Name $name): string
    {
        return $this->resolveCallable($name->toCodeString(), $this->functionUseMap);
    }

    /**
     * Resolve a php-parser Name used as a CONST fetch to its FQN, the same way
     * as {@see resolveFunctionName} but against the `use const` map.
     */
    public function resolveConstName(Name $name): string
    {
        return $this->resolveCallable($name->toCodeString(), $this->constUseMap);
    }

    /**
     * Shared function/const resolution. `$symbolUseMap` is the `use function`
     * or `use const` alias map consulted for UNqualified names only; qualified
     * names resolve their leading namespace segment via the class/namespace map.
     *
     * @param array<string, string> $symbolUseMap
     */
    private function resolveCallable(string $code, array $symbolUseMap): string
    {
        if (str_starts_with($code, '\\')) {
            return ltrim($code, '\\');
        }
        if (strncasecmp($code, 'namespace\\', 10) === 0) {
            $rest = substr($code, 10);
            return $this->currentNamespace !== ''
                ? $this->currentNamespace . '\\' . $rest
                : $rest;
        }
        if (!str_contains($code, '\\')) {
            // Unqualified: a `use function` / `use const` import binds it; else the
            // current namespace. (No leading backslash is added — the caller's
            // in-set guard decides whether to fully-qualify, so PHP's global
            // fallback survives for names the unit does not define.)
            if (isset($symbolUseMap[$code])) {
                return $symbolUseMap[$code];
            }
            return $this->currentNamespace !== ''
                ? $this->currentNamespace . '\\' . $code
                : $code;
        }
        // Qualified `A\b`: the leading segment is a namespace, brought into scope
        // by a class/namespace `use` (never a function/const import), else it is
        // relative to the current namespace.
        $first = self::firstSegment($code);
        if (isset($this->useMap[$first])) {
            return $this->useMap[$first] . substr($code, strlen($first));
        }
        return $this->currentNamespace !== ''
            ? $this->currentNamespace . '\\' . $code
            : $code;
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
        // `namespace\Foo` binds to the CURRENT namespace by PHP's rules — never
        // to a `use` alias, and never as a literal first segment (`namespace`
        // is a reserved word, so no real class name can start with it). The
        // keyword is case-insensitive.
        if (strncasecmp($name, 'namespace\\', 10) === 0) {
            $rest = substr($name, 10);
            return $this->currentNamespace !== ''
                ? $this->currentNamespace . '\\' . $rest
                : $rest;
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

    /**
     * Resolve a php-parser Name (or Identifier) honoring the node's own
     * qualification: `toCodeString()` yields `\App\Box` for a fully-qualified
     * name (the leading-backslash branch), `namespace\Box` for a relative name
     * (the relative-binding branch — a `use` alias NEVER applies to either,
     * per PHP's rules), and the plain spelling otherwise. Flattening a Name
     * with `toString()` before resolving is exactly the bug this seam removes:
     * it drops both prefixes, doubling the namespace on fully-qualified names
     * and letting the alias map capture relative ones.
     */
    public function resolveName(Name|Identifier $name): string
    {
        return $this->resolveAgainstContext(
            $name instanceof Name ? $name->toCodeString() : $name->toString(),
        );
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
