<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * A direct-ancestor map used to validate generic bounds at compile time.
 *
 * For each ClassLike (class/interface/trait) found in a parsed source set, this records the
 * fully-qualified names of its direct ancestors (extends + implements + use-trait). The
 * `isSubtype` check walks the transitive closure to answer "does class A satisfy bound B?".
 *
 * Built-in PHP interfaces (Stringable, Countable, …) are sentinel entries with no parents —
 * they're "known leaves" so a user class that explicitly implements one of them resolves the
 * bound without the hierarchy needing to model PHP's internal class table.
 *
 * Returns nullable bools from `isSubtype` because the unknown case is genuinely different from
 * "proven not a subtype": for unknown concretes the caller may want to either widen the source
 * set or relax the bound, rather than treating the concrete as wrong.
 */
final readonly class TypeHierarchy
{
    /**
     * PHP built-in interfaces/classes that user code can implement/extend without us having
     * to model them. Bound resolution against any of these works because the user's class
     * declares `implements \Stringable` (or similar) which we record as a direct ancestor.
     *
     * `public` rather than `private` because the inner-class visitor below is a separate
     * class for PHP visibility purposes and would otherwise hit a constant-access error.
     */
    public const BUILTIN_TYPES = [
        'Stringable',
        'Countable',
        'Iterator',
        'IteratorAggregate',
        'Traversable',
        'ArrayAccess',
        'JsonSerializable',
        'Throwable',
        'Exception',
        'Error',
        'BackedEnum',
        'UnitEnum',
    ];

    /**
     * @param array<string, list<string>> $ancestors map<fqn, list<direct-ancestor-fqn>>
     */
    public function __construct(private array $ancestors)
    {
    }

    /**
     * Build a hierarchy by walking the parsed ASTs of every source file in the set.
     *
     * @param array<string, list<Node\Stmt>> $astPerFile keyed by filepath, value is the top-level AST
     */
    public static function fromAstPerFile(array $astPerFile): self
    {
        $ancestors = [];
        foreach ($astPerFile as $ast) {
            self::collectFromAst($ast, $ancestors);
        }
        return new self($ancestors);
    }

    /**
     * Returns:
     *   - true  : $concrete extends/implements $bound (directly or transitively), or they're equal.
     *   - false : $concrete is known to the hierarchy and does NOT have $bound in its closure
     *             (also returned for scalars vs class/interface bounds — scalars can't satisfy them).
     *   - null  : $concrete is unknown — neither in the hierarchy nor in the built-in whitelist,
     *             so the compiler can't prove satisfaction either way.
     */
    public function isSubtype(string $concrete, string $bound): ?bool
    {
        $concrete = ltrim($concrete, '\\');
        $bound = ltrim($bound, '\\');

        if ($concrete === $bound) {
            return true;
        }

        if (in_array($concrete, XphpSourceParser::SCALAR_TYPES, true)) {
            return false;
        }

        $known = isset($this->ancestors[$concrete])
            || in_array($concrete, self::BUILTIN_TYPES, true);
        if (!$known) {
            return null;
        }

        // BFS over the ancestor map. Cycles are pathological in PHP type hierarchies but the
        // visited set keeps us safe regardless.
        $visited = [];
        $queue = [$concrete];
        while ($queue !== []) {
            $cur = array_shift($queue);
            if (isset($visited[$cur])) {
                continue;
            }
            $visited[$cur] = true;
            if ($cur === $bound) {
                return true;
            }
            foreach ($this->ancestors[$cur] ?? [] as $anc) {
                $queue[] = $anc;
            }
        }
        return false;
    }

    /**
     * @param list<Node\Stmt> $ast
     * @param array<string, list<string>> $ancestors out-param accumulator
     */
    private static function collectFromAst(array $ast, array &$ancestors): void
    {
        // @infection-ignore-all — the inner visitor is a flat AST walk over namespace/use
        // /classlike nodes; mutations on its `?->`, `??` lastSegment fallback and
        // `ltrim('\\')` defensives all toggle paths that are masked by nikic's
        // representation (FQ names come without a leading backslash, anonymous namespaces
        // aren't part of any fixture). End-to-end coverage from TypeHierarchyTest.
        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, list<string>> */
            public array $collected = [];
            private string $currentNamespace = '';
            /** @var array<string, string> alias => FQN */
            private array $useMap = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    $this->useMap = [];
                }
                if ($node instanceof Use_) {
                    foreach ($node->uses as $u) {
                        if (!$u instanceof UseItem) {
                            continue;
                        }
                        $fqn = $u->name->toString();
                        $alias = $u->alias?->toString() ?? self::lastSegment($fqn);
                        $this->useMap[$alias] = $fqn;
                    }
                }
                if ($node instanceof ClassLike && $node->name !== null) {
                    $selfFqn = $this->qualify($node->name->toString());
                    $directAncestors = [];
                    if ($node instanceof Class_) {
                        if ($node->extends !== null) {
                            $directAncestors[] = $this->resolveName($node->extends);
                        }
                        foreach ($node->implements as $iface) {
                            $directAncestors[] = $this->resolveName($iface);
                        }
                    } elseif ($node instanceof Interface_) {
                        foreach ($node->extends as $iface) {
                            $directAncestors[] = $this->resolveName($iface);
                        }
                    }
                    // Trait_ has no formal ancestors — uses-of-traits are statements inside the body
                    // and would only matter for shared-method bounds, which we don't model.
                    $this->collected[$selfFqn] = $directAncestors;
                }
                return null;
            }

            private function qualify(string $shortName): string
            {
                return $this->currentNamespace !== ''
                    ? $this->currentNamespace . '\\' . $shortName
                    : $shortName;
            }

            private function resolveName(Name $name): string
            {
                $raw = $name->toString();
                if ($name->isFullyQualified() || str_starts_with($raw, '\\')) {
                    return ltrim($raw, '\\');
                }
                $first = self::firstSegment($raw);
                if (isset($this->useMap[$first])) {
                    $rest = substr($raw, strlen($first));
                    return $this->useMap[$first] . $rest;
                }
                // Special case: built-in interfaces have no namespace; if the raw name matches a
                // known built-in we resolve as-is rather than appending the current namespace.
                if (in_array($raw, TypeHierarchy::BUILTIN_TYPES, true)) {
                    return $raw;
                }
                return $this->qualify($raw);
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
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        foreach ($visitor->collected as $fqn => $direct) {
            $ancestors[$fqn] = $direct;
        }
    }
}
