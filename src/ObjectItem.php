<?php
declare(strict_types=1);

namespace HbLib\ORM;

use function is_int;

/**
 * Class ObjectItem
 * @package HbLib\ORM
 * @phpstan-template-covariant T of IdentifiableEntityInterface
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

    public function getId(): int
    {
        $id = $this->object->getId();

        if (is_int($id) === true) {
            return $id;
        }

        throw new UnpersistedException();
    }

    /**
     * @return T
     */
    public function get()
    {
        return $this->object;
    }
}
