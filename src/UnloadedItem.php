<?php
declare(strict_types=1);

namespace HbLib\ORM;

use LogicException;

/**
 * Class UnloadedItem
 * @package HbLib\ORM
 * @phpstan-template-covariant T of IdentifiableEntityInterface
 * @phpstan-implements Item<T>
 */
final class UnloadedItem implements Item
{
    public function __construct(public int $id)
    {
    }

    /**
     * @template E of IdentifiableEntityInterface
     * @param class-string<E> $className
     * @param int $id
     * @return UnloadedItem<E>
     */
    public static function create(string $className, int $id): UnloadedItem
    {
        return new self($id);
    }

    /**
     * @return T
     */
    public function get()
    {
        throw new LogicException('Unloaded items only know their ID');
    }

    public function getId(): int
    {
        return $this->id;
    }
}
