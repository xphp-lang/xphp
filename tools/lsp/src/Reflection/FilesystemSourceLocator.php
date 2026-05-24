<?php

declare(strict_types=1);

namespace XPHP\Lsp\Reflection;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Phpactor\TextDocument\TextDocument;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\WorseReflection\Core\Exception\SourceNotFound;
use Phpactor\WorseReflection\Core\Name;
use Phpactor\WorseReflection\Core\SourceCodeLocator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use XPHP\Transpiler\Monomorphize\XphpSourceParser;

/**
 * Serve worse-reflection from the project root on disk -- the user's
 * .xphp and .php files that aren't open in the editor at the moment.
 *
 * Lookup pipeline:
 *  1. Recursively iterate the configured root directory.
 *  2. For each .xphp / .php file, parse just enough to discover declared
 *     class / interface / trait / function FQNs.
 *  3. Build a one-shot FQN -> path map, cached for the LSP session.
 *  4. On `locate()`, return a TextDocument whose source is the stripped
 *     PHP form (xphp files go through `XphpSourceParser::strip()`; .php
 *     files are returned as-is).
 *
 * Index lifetime: built lazily on the first `locate()` call, retained
 * for the LSP session.  A naive map -- doesn't pick up files added or
 * renamed after the LSP started.  Refresh requires an LSP restart.
 * That's acceptable for an MVP; a file watcher is a follow-up.
 *
 * Skipped paths (hardcoded sane defaults; the LSP doesn't have a
 * project-config notion yet):
 *  - `.git/`           -- never source
 *  - `vendor/`         -- composer-installed; phpactor's stub locator
 *                         covers PHP standard library, and pulling
 *                         hundreds of MB of vendor through nikic would
 *                         dominate the index build
 *  - `node_modules/`   -- JS, irrelevant
 *  - `tools/lsp/var/`  -- our own PHAR build artefacts
 *
 * If/when we surface a richer per-project config (composer.json
 * autoload, .gitignore, etc.) this list grows; for now the constants
 * are explicit so it's obvious why a given file isn't indexed.
 */
final class FilesystemSourceLocator implements SourceCodeLocator
{
    private const SKIP_DIRS = ['.git', 'vendor', 'node_modules', 'var', 'build'];

    private const SKIP_EXTENSIONS = [];

    /** @var array<string, string>|null  FQN => absolute path */
    private ?array $map = null;

    private readonly Parser $phpParser;

    public function __construct(
        private readonly string $rootPath,
        private readonly XphpSourceParser $xphpParser,
    ) {
        $this->phpParser = (new ParserFactory())->createForHostVersion();
    }

    public function locate(Name $name): TextDocument
    {
        $needle = ltrim((string) $name, '\\');
        $map = $this->map();

        if (!isset($map[$needle])) {
            throw new SourceNotFound(sprintf(
                'No file under "%s" declares "%s"',
                $this->rootPath,
                $needle,
            ));
        }

        $path = $map[$needle];
        $source = @file_get_contents($path);
        if ($source === false) {
            // Indexed file disappeared between map build and lookup.
            // Drop it from the map so retries don't keep trying, then signal
            // not-found to worse-reflection's locator chain.
            unset($this->map[$needle]);
            throw new SourceNotFound(sprintf(
                'Indexed source disappeared from disk: "%s"',
                $path,
            ));
        }

        $stripped = self::shouldStrip($path) ? $this->xphpParser->strip($source) : $source;

        return TextDocumentBuilder::create($stripped)
            ->uri($path)
            ->language('php')
            ->build();
    }

    /**
     * @return array<string, string>
     */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $map = [];
        if (!is_dir($this->rootPath)) {
            $this->map = $map;
            return $map;
        }

        foreach ($this->iterator() as $file) {
            /** @var SplFileInfo $file */
            $ext = $file->getExtension();
            if ($ext !== 'php' && $ext !== 'xphp') {
                continue;
            }

            $source = @file_get_contents($file->getPathname());
            if ($source === false) {
                continue;
            }

            $php = ($ext === 'xphp') ? $this->xphpParser->strip($source) : $source;

            try {
                $ast = $this->phpParser->parse($php);
            } catch (\Throwable) {
                continue;
            }
            if ($ast === null) {
                continue;
            }

            foreach (self::collectFqns($ast) as $fqn) {
                $map[$fqn] = $file->getPathname();
            }
        }

        $this->map = $map;
        return $map;
    }

    private function iterator(): RecursiveIteratorIterator
    {
        $directoryIterator = new RecursiveDirectoryIterator(
            $this->rootPath,
            RecursiveDirectoryIterator::SKIP_DOTS,
        );

        $filter = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (SplFileInfo $file): bool {
                if ($file->isDir()) {
                    return !in_array($file->getFilename(), self::SKIP_DIRS, true);
                }
                return true;
            },
        );

        return new RecursiveIteratorIterator($filter);
    }

    private static function shouldStrip(string $path): bool
    {
        return str_ends_with($path, '.xphp');
    }

    /**
     * @param list<Node\Stmt> $ast
     * @return list<string>
     */
    private static function collectFqns(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<string> */
            public array $fqns = [];
            private string $currentNamespace = '';

            public function enterNode(Node $node): null
            {
                if ($node instanceof Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    return null;
                }
                $short = null;
                if ($node instanceof ClassLike && $node->name !== null) {
                    $short = $node->name->toString();
                } elseif ($node instanceof Function_) {
                    $short = $node->name->toString();
                }
                if ($short !== null) {
                    $this->fqns[] = $this->currentNamespace !== ''
                        ? $this->currentNamespace . '\\' . $short
                        : $short;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        /** @var list<string> */
        return $visitor->fqns;
    }
}
