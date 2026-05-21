<?php

declare(strict_types=1);

namespace XPHP\Lsp\Handler;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Walks an AST and locates the smallest Name node whose byte range contains a
 * given offset. Also captures the stack of enclosing ClassLike nodes at the hit
 * site — handlers need that to look up type-params declared on the surrounding
 * template (e.g. resolving `T` in `public T $item` requires knowing which
 * ClassLike the `T` lives inside).
 *
 * Used by HoverHandler (and, later, DefinitionHandler) — extracted so the
 * resolution logic is testable in isolation.
 */
final readonly class AstPositionResolver
{
    /**
     * @param list<Node\Stmt> $ast
     * @return array{name: Name, classScope: list<ClassLike>}|null
     */
    public static function nameAtOffset(array $ast, int $offset): ?array
    {
        $visitor = new class($offset) extends NodeVisitorAbstract {
            /** @var list<ClassLike> */
            private array $classStack = [];

            public ?Name $found = null;

            /** @var list<ClassLike> */
            public array $foundClassStack = [];

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof ClassLike) {
                    $this->classStack[] = $node;
                }
                if (!$node instanceof Name) {
                    return null;
                }
                $start = $node->getStartFilePos();
                $end = $node->getEndFilePos();
                if ($start < 0 || $end < 0) {
                    return null;
                }
                // endFilePos points at the LAST byte of the node (inclusive),
                // so the half-open range is [start, end + 1).
                if ($this->offset < $start || $this->offset > $end) {
                    return null;
                }
                // Keep the SMALLEST matching node — replace any previous match
                // because nikic walks parents before children.
                $this->found = $node;
                $this->foundClassStack = $this->classStack;
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof ClassLike) {
                    array_pop($this->classStack);
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        if ($visitor->found === null) {
            return null;
        }
        return ['name' => $visitor->found, 'classScope' => $visitor->foundClassStack];
    }
}
