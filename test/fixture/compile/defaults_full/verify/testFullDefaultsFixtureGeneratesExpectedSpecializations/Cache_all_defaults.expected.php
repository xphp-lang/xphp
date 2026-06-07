<?php

declare (strict_types=1);
namespace XPHP\Generated\App\DefaultsFull\Containers\Cache;

// `Cache<K = string, V = mixed>` -- both params defaulted. Call sites can omit
// any trailing tail; `new Cache;` / `new Cache::<>` / `new Cache::<int>` /
// `new Cache::<int, Tag>` all specialize cleanly.
class T_2e416e69cdf199e1c2b4b26d6edf5d7653f1c92ce31ae41ec2e5f04dd3a026fb implements \App\DefaultsFull\Containers\Cache
{
    /** @var array<K, V> */
    public array $entries = [];
    public function set(string $key, mixed $value): void
    {
        $this->entries[$key] = $value;
    }
}
