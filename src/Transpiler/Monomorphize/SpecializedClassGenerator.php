<?php

declare(strict_types=1);

namespace XPHP\Transpiler\Monomorphize;

use PhpParser\Node\DeclareItem;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use XPHP\FileSystem\FileWriter;

/**
 * Emits a specialized class file. The target FQCN comes from Registry::generatedFqn() and has
 * the shape `XPHP\Generated\<original-template-fqcn>\T_<hash>`. The on-disk path mirrors the
 * namespace under cacheDir/Generated/, so a PSR-4 entry `XPHP\Generated\ => .xphp-cache/Generated/`
 * autoloads everything.
 */
final class SpecializedClassGenerator
{
    public function __construct(
        private readonly StandardPrinter $printer,
        private readonly FileWriter $fileWriter,
    ) {
    }

    public function emit(ClassLike $specialized, string $generatedFqn, string $cacheDir): string
    {
        $pos = strrpos($generatedFqn, '\\');
        $namespace = $pos === false ? '' : substr($generatedFqn, 0, $pos);
        $classShortName = $pos === false ? $generatedFqn : substr($generatedFqn, $pos + 1);

        $specialized->name = new Identifier($classShortName);

        $nsAst = $namespace === ''
            ? null
            : new Namespace_(new Name($namespace), [$specialized]);

        $declare = new Declare_([new DeclareItem('strict_types', new Int_(1))]);

        $code = $this->printer->prettyPrintFile(
            $nsAst !== null ? [$declare, $nsAst] : [$declare, $specialized],
        );

        $prefix = Registry::GENERATED_NAMESPACE_PREFIX . '\\';
        $relNs = str_starts_with($namespace, $prefix)
            ? substr($namespace, strlen($prefix))
            : $namespace;
        $relPath = str_replace('\\', '/', $relNs);

        $outputDir = rtrim($cacheDir, '/') . '/Generated' . ($relPath !== '' ? '/' . $relPath : '');
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0o755, true);
        }

        $path = $outputDir . '/' . $classShortName . '.php';
        $this->fileWriter->write($path, $code);

        return $path;
    }
}
