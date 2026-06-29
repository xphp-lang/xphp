<?php

declare(strict_types=1);

// Characterization runner for the subtype-related grouping limitation: the program COMPILES cleanly,
// but the generated specializations are incompatible at PHP class-load time. The incompatibility is a
// non-catchable fatal, so it is observed from a child process (driven by the test via `exec`) rather
// than in-process: this script forces every generated specialization to load and prints LOADED_OK only
// if none fataled. Argv: <targetDir> <cacheDir>.

use Composer\Autoload\ClassLoader;
use XPHP\Transpiler\Monomorphize\Registry;

require __DIR__ . '/../../../../../vendor/autoload.php';

[$targetDir, $cacheDir] = [$argv[1], $argv[2]];

$loader = new ClassLoader();
$loader->addPsr4('App\\', $targetDir);
$loader->addPsr4(Registry::GENERATED_NAMESPACE_PREFIX . '\\', $cacheDir . '/Generated');
$loader->register();

foreach (glob($cacheDir . '/Generated/App/*/T_*.php') ?: [] as $file) {
    $rel = substr($file, strlen($cacheDir . '/Generated/'), -4); // strip prefix + ".php"
    $fqn = Registry::GENERATED_NAMESPACE_PREFIX . '\\' . str_replace('/', '\\', $rel);
    class_exists($fqn); // forces declaration -> links to parent/interface -> fatals if incompatible
}

echo "LOADED_OK\n";
