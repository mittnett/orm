<?php
declare(strict_types=1);

namespace HbLib\ORM;

use function array_key_exists;
use function array_search;
use function in_array;
use function is_int;

/**
 * Class ArrayCollection
 * @package HbLib\ORM
 * @phpstan-template T
 * @phpstan-implements Collection<T>
 */
class ArrayCollection implements Collection
{
    /**
     * ArrayCollection constructor.
     * @param array<int, T> $items
     */
    public function __construct(
        protected array $items = []
    ) {
        //
    }

    /**
     * @param int $offset
     * @return void
     */
    public function offsetUnset($offset)
    {
        unset($this->items[$offset]);
    }

    /**
     * @param int|null $offset
     * @param T $value
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        if ($offset !== null) {
            $this->items[$offset] = $value;
        } else {
            $this->items[] = $value;
        }
    }

    /**
     * @param int $offset
     * @return T|null
     */
    public function offsetGet($offset)
    {
        return $this->items[$offset] ?? null;
    }

    /**
     * @param int $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->items) === true;
    }

    public function add($object): bool
    {
        if ($this->contains($object) === false) {
            $this->offsetSet(null, $object);
            return true;
        }

        return false;
    }

    public function contains($object): bool
    {
        return in_array($object, $this->items, true) === true;
    }

    public function remove($object): bool
    {
        $key = array_search($object, $this->items, true);

        if ($key !== false) {
            $this->offsetUnset($key);
            return true;
        }

        return false;
    }

    public function getIterator()
    {
        yield from $this->items;
    }

    public function count()
    {
        return count($this->items);
    }
}
