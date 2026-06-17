<?php

declare (strict_types=1);
namespace XPHP\Generated\App\BuiltinViaUse\ArrayBag;

class T_729a900208fdde1d2fd72168cd42098ff2c2d6155f1713a6e10b4aafa854674e extends \App\BuiltinViaUse\AbstractBag implements \XPHP\Generated\App\BuiltinViaUse\Bag\T_729a900208fdde1d2fd72168cd42098ff2c2d6155f1713a6e10b4aafa854674e, \Countable, \App\BuiltinViaUse\ArrayBag
{
    // Typed (enum) class constant: the type `Color` is a same-namespace bare name
    // that must be qualified in the relocated class — the `ClassConst` position.
    public const \App\BuiltinViaUse\Color DEFAULT_COLOR = \App\BuiltinViaUse\Color::Red;
    private array $items = [];
    // Nullable + union type-hints referencing `use`-imported built-ins: exercise
    // the NullableType and UnionType arms of the resolver in a relocated class.
    private ?\Traversable $snapshot = null;
    private \Countable|\Traversable|null $meta = null;
    public function add(\App\BuiltinViaUse\Models\User $item): void
    {
        $this->items[] = $item;
    }
    public function count(): int
    {
        // Bare function call: must stay bare (PHP global fallback) even after relocation.
        return \count($this->items);
    }
    public function getIterator(): \Traversable
    {
        // Return-type hint + `new` of a `use`-imported built-in class.
        return new \ArrayIterator($this->items);
    }
    // Param typed with an imported class; `instanceof` + class-constant fetch on
    // imported classes inside a relocated body.
    public function accepts(\App\BuiltinViaUse\Errors\EmptyBagError $probe): bool
    {
        $iter = new \ArrayIterator($this->items);
        $flags = \ArrayIterator::STD_PROP_LIST;
        return $flags >= 0 && $iter instanceof \Traversable && $probe instanceof \App\BuiltinViaUse\Errors\EmptyBagError;
    }
    // Closure with an imported return-type hint, nested in a relocated method.
    public function makeFactory(): callable
    {
        return function (): \Traversable {
            return new \ArrayIterator($this->items);
        };
    }
    public function first(): \App\BuiltinViaUse\Models\User
    {
        try {
            if ($this->items === []) {
                // `new` of a `use`-imported exception class.
                throw new \App\BuiltinViaUse\Errors\EmptyBagError('bag is empty');
            }
            return $this->items[0];
        } catch (\App\BuiltinViaUse\Errors\EmptyBagError $e) {
            // `catch` of a `use`-imported exception class.
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
