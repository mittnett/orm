<?php
declare(strict_types=1);

namespace HbLib\ORM;

use ArrayAccess;
use Countable;
use IteratorAggregate;

/**
 * Interface Collection
 * @package HbLib\ORM
 * @phpstan-template T
 * @phpstan-extends IteratorAggregate<int, T>
 * @phpstan-extends ArrayAccess<int, T>
 */
interface Collection extends IteratorAggregate, Countable, ArrayAccess
{
    /**
     * @param T $object
     * @return bool
     */
    public function add($object): bool;

    /**
     * @param T $object
     * @return bool
     */
    public function contains($object): bool;

    /**
     * @param T $object
     * @return bool
     */
    public function remove($object): bool;
}
