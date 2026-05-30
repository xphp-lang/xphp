<?php

declare(strict_types=1);

namespace XPHP\Lsp\Resolver;

use PhpParser\ErrorHandler\Collecting as CollectingErrorHandler;
use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Snapshot of a file's enclosing namespace + class-import map, used by
 * completion + code-action resolvers to choose the shortest source-form
 * of an FQN that resolves correctly in that file.
 *
 * Why this matters: bare `App\Models\Tag` inserted into a file with
 * `namespace App\Demos;` resolves (per PHP's name-resolution rules) to
 * `App\Demos\App\Models\Tag` -- the namespace gets prepended to anything
 * that isn't either fully-qualified (leading `\`) or starting with an
 * imported segment. The completion handler used to emit the bare FQN as
 * insertText, which fired spurious bound-violation diagnostics and would
 * autoload-fail at runtime. {@see chooseInsertText} returns the form
 * that resolves correctly without touching the file's import list.
 */
final class ClassNameImportContext
{
    /**
     * @param array<string, string> $useMap alias => FQN (no leading backslash)
     */
    public function __construct(
        public readonly string $namespace,
        public readonly array $useMap,
    ) {
    }

    /**
     * Parse `$source` with a tolerant error handler and extract the
     * namespace + class-import map. Used by completion paths that have
     * the document text but no pre-parsed AST -- the tolerant handler
     * lets us recover context even when the cursor is mid-edit and the
     * tail of the file is syntactically incomplete.
     *
     * Returns an empty context (no namespace, no imports) if the parser
     * can't yield any AST -- callers treat that as "fall back to FQ".
     */
    public static function extractFromSource(string $source): self
    {
        try {
            $parser = (new ParserFactory())->createForHostVersion();
            $ast = $parser->parse($source, new CollectingErrorHandler());
        } catch (Throwable) {
            $ast = null;
        }
        if ($ast === null) {
            return new self('', []);
        }
        return self::extract($ast);
    }

    /**
     * Walk the top-level AST to extract the enclosing namespace + every
     * class-import (`use Foo\Bar;` and `use Foo\{Bar, Baz};`). Function /
     * const imports are skipped -- they live in separate symbol tables
     * and don't bind class-like aliases.
     *
     * @param list<Node\Stmt> $ast
     */
    public static function extract(array $ast): self
    {
        $namespace = '';
        $useMap = [];
        // Either the file has a `namespace App\Foo;` declaration whose
        // body contains the top-level statements, or it's namespace-less
        // and the stmts are the top-level array directly.
        $stmts = $ast;
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $namespace = $stmt->name === null ? '' : $stmt->name->toString();
                $stmts = $stmt->stmts;
                break;
            }
        }
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Use_) {
                foreach ($stmt->uses as $useUse) {
                    $type = $useUse->type !== Use_::TYPE_UNKNOWN
                        ? $useUse->type
                        : $stmt->type;
                    if ($type !== Use_::TYPE_NORMAL) {
                        continue;
                    }
                    $useMap[$useUse->getAlias()->toString()] = $useUse->name->toString();
                }
                continue;
            }
            if ($stmt instanceof GroupUse) {
                $prefix = $stmt->prefix->toString();
                foreach ($stmt->uses as $useUse) {
                    $type = $useUse->type !== Use_::TYPE_UNKNOWN
                        ? $useUse->type
                        : $stmt->type;
                    if ($type !== Use_::TYPE_NORMAL) {
                        continue;
                    }
                    $useMap[$useUse->getAlias()->toString()] = $prefix . '\\' . $useUse->name->toString();
                }
            }
        }
        return new self($namespace, $useMap);
    }

    /**
     * Pick the most idiomatic source-form of `$fqn` to insert into this
     * file. Precedence:
     *   1. Existing import (incl. `use Foo as Bar;` alias) → insert alias.
     *   2. Same-namespace AND no conflicting short-name use → insert short.
     *   3. Otherwise → insert `\FQN` (leading backslash → always FQ).
     *
     * The conflict guard in (2) prevents inserting bare `Tag` when the
     * file has `use App\Other\Tag;` -- `Tag` would resolve to
     * `App\Other\Tag` instead of the intended FQN.
     */
    public function chooseInsertText(string $fqn): string
    {
        $fqn = ltrim($fqn, '\\');
        foreach ($this->useMap as $alias => $mappedFqn) {
            if (ltrim($mappedFqn, '\\') === $fqn) {
                return $alias;
            }
        }
        $short = self::lastSegment($fqn);
        $parent = self::parentNamespace($fqn);
        if ($parent === $this->namespace && !isset($this->useMap[$short])) {
            return $short;
        }
        return '\\' . $fqn;
    }

    private static function lastSegment(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }

    private static function parentNamespace(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos === false ? '' : substr($fqn, 0, $pos);
    }
}
