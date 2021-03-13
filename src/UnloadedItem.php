<?php
declare(strict_types=1);

namespace HbLib\ORM;

use LogicException;

/**
 * Class UnloadedItem
 * @package HbLib\ORM
 * @phpstan-template T of IdentifiableEntityInterface
 * @phpstan-implements Item<T>
 */
final class UnloadedItem implements Item
{
    public function __construct(public int $id)
    {
    }

    public function get()
    {
        throw new LogicException('Unloaded items only know their ID');
    }

    public function getId(): int
    {
        return $this->id;
    }
}
