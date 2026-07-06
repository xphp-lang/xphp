<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Extraction of a closure literal's declared signature into a candidate
 * {@see ClosureSignature}. Types resolve against a supplied namespace context;
 * untyped/absent/compound slots stay gradual.
 */
final class ClosureLiteralSignatureTest extends TestCase
{
    public function testExtractsTypedParametersAndReturn(): void
    {
        $sig = self::extract(
            '<?php $f = function (int $x, Fruit $y): Apple { return new Apple(); };',
            self::ctx('App', ['Fruit' => 'App\\Models\\Fruit']),
        );

        self::assertCount(2, $sig->params);
        self::assertSame('int', self::typeName($sig->params[0]->type));
        self::assertTrue(self::refIsScalar($sig->params[0]->type));
        self::assertSame('App\\Models\\Fruit', self::typeName($sig->params[1]->type), 'a use-imported class resolves via the alias');
        self::assertSame('App\\Apple', self::typeName($sig->return), 'a bare class resolves against the current namespace');
    }

    public function testUntypedParameterBecomesMixed(): void
    {
        $sig = self::extract('<?php $f = fn($x) => $x;', self::ctx());

        self::assertCount(1, $sig->params);
        self::assertSame('mixed', self::typeName($sig->params[0]->type));
    }

    public function testAbsentReturnStaysAbsent(): void
    {
        $sig = self::extract('<?php $f = fn(int $x) => $x;', self::ctx());

        self::assertNull($sig->return, 'no return type ⇒ absent (gradual), distinct from mixed');
    }

    public function testByRefAndVariadicAndOptionalAreCaptured(): void
    {
        $sig = self::extract('<?php $f = function (int &$r, int $o = 0, string ...$rest) {};', self::ctx());

        self::assertCount(3, $sig->params);
        self::assertTrue($sig->params[0]->byRef);
        self::assertFalse($sig->params[0]->optional);
        self::assertTrue($sig->params[1]->optional);
        self::assertTrue($sig->params[2]->variadic);
    }

    public function testNullableAndUnionTypesAreCarriedRaw(): void
    {
        $sig = self::extract('<?php $f = function (?int $a, int|string $b): int|null { return 1; };', self::ctx());

        self::assertInstanceOf(SigRaw::class, $sig->params[0]->type);
        self::assertSame('?int', $sig->params[0]->type->raw);
        self::assertInstanceOf(SigRaw::class, $sig->params[1]->type);
        self::assertSame('int|string', $sig->params[1]->type->raw);
        self::assertInstanceOf(SigRaw::class, $sig->return);
        self::assertSame('int|null', $sig->return->raw);
    }

    public function testIntersectionTypeIsCarriedRaw(): void
    {
        $sig = self::extract('<?php $f = function (): Countable&Traversable { return null; };', self::ctx('App'));

        self::assertInstanceOf(SigRaw::class, $sig->return);
        self::assertSame('Countable&Traversable', $sig->return->raw);
    }

    public function testArrowFunctionReturnIsExtracted(): void
    {
        $sig = self::extract('<?php $f = fn(int $x): string => (string) $x;', self::ctx());

        self::assertCount(1, $sig->params);
        self::assertSame('string', self::typeName($sig->return));
    }

    public function testBuiltinArrayAndCallableResolveAsBuiltins(): void
    {
        $sig = self::extract('<?php $f = function (array $a, callable $c): void {};', self::ctx('App'));

        self::assertSame('array', self::typeName($sig->params[0]->type));
        self::assertSame('callable', self::typeName($sig->params[1]->type));
        self::assertSame('void', self::typeName($sig->return));
    }

    public function testCapitalCasedScalarKeywordsAreRecognized(): void
    {
        // PHP type keywords are case-insensitive; `Int`/`Bool` must fold to the
        // canonical lowercase scalar, not be mistaken for class names.
        $sig = self::extract('<?php $f = function (Int $x): Bool { return true; };', self::ctx('App'));

        self::assertSame('int', self::typeName($sig->params[0]->type));
        self::assertTrue(self::refIsScalar($sig->params[0]->type));
        self::assertSame('bool', self::typeName($sig->return));
    }

    // ---- Helpers ---------------------------------------------------------

    private static function extract(string $source, NamespaceContext $ctx): ClosureSignature
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($source) ?? [];
        $literal = (new NodeFinder())->findFirst($ast, static fn (Node $n): bool => $n instanceof Closure || $n instanceof ArrowFunction);
        self::assertInstanceOf(Node::class, $literal);
        \assert($literal instanceof Closure || $literal instanceof ArrowFunction);

        return ClosureLiteralSignature::extract($literal, $ctx);
    }

    /**
     * @param array<string, string> $uses alias => FQN
     */
    private static function ctx(string $namespace = '', array $uses = []): NamespaceContext
    {
        $ctx = new NamespaceContext();
        $ctx->enterNamespace($namespace === '' ? null : $namespace);
        foreach ($uses as $alias => $fqn) {
            $ctx->indexUse(new \PhpParser\Node\Stmt\Use_([
                new \PhpParser\Node\UseItem(new \PhpParser\Node\Name($fqn), new \PhpParser\Node\Identifier($alias)),
            ]));
        }
        return $ctx;
    }

    private static function typeName(?SigType $type): ?string
    {
        return $type instanceof SigTypeRef ? $type->type->name : null;
    }

    private static function refIsScalar(SigType $type): bool
    {
        return $type instanceof SigTypeRef && $type->type->isScalar;
    }
}
