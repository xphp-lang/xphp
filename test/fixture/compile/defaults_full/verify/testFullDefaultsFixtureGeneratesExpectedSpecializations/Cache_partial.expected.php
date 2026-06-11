<?php

declare (strict_types=1);
namespace XPHP\Generated\App\DefaultsFull\Containers\Cache;

// `Cache<K = string, V = mixed>` -- both params defaulted. Call sites can omit
// any trailing tail; `new Cache;` / `new Cache::<>` / `new Cache::<int>` /
// `new Cache::<int, Tag>` all specialize cleanly.
class T_35d06b6955c0c22714fba211b6e0fa0a8deee7383b4832bcd92caba9f48a3b59 implements \App\DefaultsFull\Containers\Cache
{
    /** @var array<K, V> */
    public array $entries = [];
    public function set(int $key, mixed $value): void
    {
        $this->entries[$key] = $value;
    }
}
