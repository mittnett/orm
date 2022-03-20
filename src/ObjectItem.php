<?php
declare(strict_types=1);

namespace HbLib\ORM;

use function is_int;

/**
 * Class ObjectItem
 * @package HbLib\ORM
 * @phpstan-template T
 * @phpstan-implements Item<T>
 */
final class ObjectItem implements Item
{
    /**
     * @var T
     */
    private $object;

    /**
     * ObjectItem constructor.
     * @param T $object
     */
    public function __construct($object)
    {
        $this->object = $object;
    }

    /**
     * @template TE of IdentifiableEntityInterface
     * @param TE $object
     * @return int
     */
    private static function getObjectId(object $object): int
    {
        $id = $object->getId();

        if (is_int($id) === true) {
            return $id;
        }

        throw new UnpersistedException();
    }

    public function getId(): int
    {
        return self::getObjectId($this->object);
    }

    public function get()
    {
        return $this->object;
    }
}
