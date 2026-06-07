<?php

declare (strict_types=1);
namespace XPHP\Generated\App\DefaultsFull\Containers\Cache;

// `Cache<K = string, V = mixed>` -- both params defaulted. Call sites can omit
// any trailing tail; `new Cache;` / `new Cache::<>` / `new Cache::<int>` /
// `new Cache::<int, Tag>` all specialize cleanly.
class T_05d9735a0eb523f1e9d5b2b2c2e5655a2e30b255ed7c840d69e81c4fd8b4a12c implements \App\DefaultsFull\Containers\Cache
{
    /** @var array<K, V> */
    public array $entries = [];
    public function set(int $key, \App\DefaultsFull\Models\Tag $value): void
    {
        $this->entries[$key] = $value;
    }
}
