<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * The parser tags bare, single-segment, non-imported class names used inside a
 * generic context with ATTR_SUSPECT_UNDECLARED_TYPE. The undeclared-type-parameter
 * validator (WI-02) reads it; these tests pin exactly which names get tagged.
 */
final class SuspectUndeclaredTypeTagTest extends TestCase
{
    public function testStrayTypeNameInGenericTemplateIsTagged(): void
    {
        $tagged = $this->taggedNames(<<<'PHP'
            <?php
            namespace App;
            interface Foo<Z>
            {
                public function add(T $x): void;
                public function get(): Z;
            }
            PHP);

        // `T` (undeclared) is tagged with its namespace-resolved FQN; `Z` is a
        // declared param (never qualified, never tagged).
        self::assertSame(['T' => 'App\\T'], $tagged);
    }

    public function testStrayNamesInPropertyAndReturnPositionsAreTagged(): void
    {
        $tagged = $this->taggedNames(<<<'PHP'
            <?php
            namespace App;
            class Box<T>
            {
                public Stray $item;
                public function get(): Other {}
            }
            PHP);

        // Locks the contract that WI-02 relies on: property + return-type positions
        // (not just params) are tagged.
        self::assertSame(['Stray' => 'App\\Stray', 'Other' => 'App\\Other'], $tagged);
    }

    public function testImportedNameIsNotTagged(): void
    {
        $tagged = $this->taggedNames(<<<'PHP'
            <?php
            namespace App;
            use App\Models\Real;
            class Box<T>
            {
                public function set(Real $x): void {}
            }
            PHP);

        self::assertSame([], $tagged);
    }

    public function testFullyQualifiedAndScalarAreNotTagged(): void
    {
        $tagged = $this->taggedNames(<<<'PHP'
            <?php
            namespace App;
            class Box<T>
            {
                public function a(\App\Thing $x): void {}
                public function b(int $x): int { return $x; }
            }
            PHP);

        self::assertSame([], $tagged);
    }

    public function testBareNameInNonGenericClassIsNotTagged(): void
    {
        $tagged = $this->taggedNames(<<<'PHP'
            <?php
            namespace App;
            class Plain
            {
                public function add(Stray $x): void {}
            }
            PHP);

        // No enclosing type parameters → not a generic context → nothing tagged.
        self::assertSame([], $tagged);
    }

    public function testStrayNameInGenericMethodOfPlainClassIsTagged(): void
    {
        $tagged = $this->taggedNames(<<<'PHP'
            <?php
            namespace App;
            class Util
            {
                public function id<A>(B $x): void {}
            }
            PHP);

        // The method declares <A>, so we're in a generic context; the stray `B` is tagged.
        self::assertSame(['B' => 'App\\B'], $tagged);
    }

    /**
     * @return array<string, string> tagged short-name => resolved FQN
     */
    private function taggedNames(string $source): array
    {
        $ast = (new XphpSourceParser((new ParserFactory())->createForHostVersion()))->parse($source);

        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, string> */
            public array $tagged = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Name) {
                    $fqn = $node->getAttribute(XphpSourceParser::ATTR_SUSPECT_UNDECLARED_TYPE);
                    if (is_string($fqn)) {
                        $this->tagged[$node->toString()] = $fqn;
                    }
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->tagged;
    }
}
