<?php

declare(strict_types=1);

namespace XPHP\Config;

use XPHP\FileSystem\FilepathArray;

/**
 * The fully-resolved source set for one build, produced by {@see ManifestResolver}: every `.xphp`
 * file to compile (own + transitively included), each mapped to the source root it was found under
 * (for root-aware emit — see `Compiler::compile`), plus the entry manifest's optional `target`/`cache`.
 */
final readonly class ResolvedSources
{
    /**
     * @param array<string,string> $rootByFile absolute `.xphp` filepath → its source-root dir
     * @param ?string $target absolute build-output dir from the entry manifest (null if unset)
     * @param ?string $cache absolute generated-class cache dir from the entry manifest (null if unset)
     */
    public function __construct(
        public FilepathArray $files,
        public array $rootByFile,
        public ?string $target,
        public ?string $cache,
    ) {
    }
}
