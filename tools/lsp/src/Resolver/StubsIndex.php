<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Enumerated index of every function FQN and class FQN inside a directory
 * of PHP stub files (typically `jetbrains/phpstorm-stubs`).  Used by the
 * completion resolver to suggest native functions and classes by prefix --
 * worse-reflection's `Reflector` is lookup-by-name only and doesn't expose
 * an "iterate everything" API, so we build the index ourselves.
 *
 * Storage: parses every `.php` file in the directory once, walks the AST
 * to collect declarations, writes a sorted JSON map to
 * `<stubsDir>/.completion-index.json`.  Subsequent `loadOrBuild()` calls
 * read the JSON directly (~milliseconds vs. ~1-2 seconds for the cold
 * walk through ~3000 phpstorm-stubs files).
 *
 * Cache invalidation: the JSON lives inside the stubs cache directory,
 * which itself is sha-keyed by the source path in
 * `ReflectorFactory::extractStubsCache()`.  Plugin upgrades land a new
 * extraction in a fresh sha-keyed directory and pay one-time index
 * build there; older caches go orphan but harmlessly.
 */
final class StubsIndex
{
    /** Filename of the cached JSON map, kept inside the stubs cache dir. */
    public const INDEX_FILENAME = '.completion-index.json';

    /**
     * @param list<string> $functions  Sorted FQNs of every top-level function.
     * @param list<string> $classes    Sorted FQNs of every class / interface / trait / enum.
     */
    public function __construct(
        public readonly array $functions,
        public readonly array $classes,
    ) {
    }

    /**
     * Load the cached JSON map at `<stubsDir>/.completion-index.json` if it
     * exists, otherwise build the index by walking the stubs tree and
     * persist the result before returning.
     *
     * When `$stubsDir` is missing or empty, returns an empty index --
     * means "no native completion candidates" rather than erroring.
     */
    public static function loadOrBuild(string $stubsDir): self
    {
        $cachePath = $stubsDir . '/' . self::INDEX_FILENAME;
        if (is_file($cachePath)) {
            $raw = @file_get_contents($cachePath);
            if ($raw !== false) {
                $data = @json_decode($raw, true);
                if (is_array($data)
                    && isset($data['functions'])
                    && isset($data['classes'])
                    && is_array($data['functions'])
                    && is_array($data['classes'])
                ) {
                    return new self(
                        /** @phpstan-ignore-next-line */
                        functions: array_values($data['functions']),
                        /** @phpstan-ignore-next-line */
                        classes: array_values($data['classes']),
                    );
                }
            }
        }

        return self::buildAndPersist($stubsDir, $cachePath);
    }

    private static function buildAndPersist(string $stubsDir, string $cachePath): self
    {
        if (!is_dir($stubsDir)) {
            return new self(functions: [], classes: []);
        }

        $parser = (new ParserFactory())->createForHostVersion();
        /** @var array<string, bool> $functions */
        $functions = [];
        /** @var array<string, bool> $classes */
        $classes = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stubsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if (!$item->isFile() || $item->getExtension() !== 'php') {
                continue;
            }
            $source = @file_get_contents($item->getPathname());
            if ($source === false) {
                continue;
            }
            try {
                $ast = $parser->parse($source);
            } catch (Throwable) {
                continue;
            }
            if ($ast === null) {
                continue;
            }
            self::collectFromAst($ast, $functions, $classes);
        }

        $functionList = array_keys($functions);
        $classList = array_keys($classes);
        sort($functionList);
        sort($classList);

        @file_put_contents($cachePath, json_encode([
            'functions' => $functionList,
            'classes' => $classList,
        ]));

        return new self(functions: $functionList, classes: $classList);
    }

    /**
     * @param list<Node\Stmt> $ast
     * @param array<string, bool> $functions
     * @param array<string, bool> $classes
     */
    private static function collectFromAst(array $ast, array &$functions, array &$classes): void
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var array<string, bool> */
            public array $functions = [];
            /** @var array<string, bool> */
            public array $classes = [];

            private string $namespace = '';

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->namespace = $node->name?->toString() ?? '';
                    return null;
                }
                if ($node instanceof Function_) {
                    $this->functions[$this->fqn($node->name->toString())] = true;
                    return null;
                }
                if ($node instanceof ClassLike && $node->name !== null) {
                    $this->classes[$this->fqn($node->name->toString())] = true;
                }
                return null;
            }

            private function fqn(string $short): string
            {
                return $this->namespace !== ''
                    ? $this->namespace . '\\' . $short
                    : $short;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        foreach ($visitor->functions as $name => $_) {
            $functions[$name] = true;
        }
        foreach ($visitor->classes as $name => $_) {
            $classes[$name] = true;
        }
    }
}
