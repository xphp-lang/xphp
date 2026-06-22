<?php

declare(strict_types=1);

namespace XPHP\Config;

/**
 * A parsed `xphp.json` manifest: a package's declaration of its own `.xphp` source roots and the
 * other packages it pulls in. All paths are as written (relative to the manifest's own directory);
 * resolution to absolute filesystem roots happens in {@see ManifestResolver}.
 */
final readonly class Manifest
{
    /**
     * @param list<string> $sources This package's own `.xphp` source roots (relative). The parser
     *   substitutes `["."]` (the manifest's own dir) when the key is absent.
     * @param list<string> $include Other packages to pull in transitively — each a dir or a glob
     *   (`*`/`?`/`[…]` per segment; recursive `**` is rejected). The parser substitutes `[]` when absent.
     * @param ?string $target Optional build output dir (entry manifest only; CLI overrides).
     * @param ?string $cache Optional generated-class cache dir (entry manifest only; CLI overrides).
     */
    public function __construct(
        public array $sources,
        public array $include,
        public ?string $target,
        public ?string $cache,
    ) {
    }
}
