<?php
declare(strict_types=1);

namespace HbLib\ORM;

use HbLib\ORM\Attribute\ManyToOne;
use HbLib\ORM\Attribute\OneToOne;
use LogicException;
use RuntimeException;
use function array_pop;

/**
 * Class LazyItem
 * @package HbLib\ORM
 * @phpstan-template T of IdentifiableEntityInterface
 * @phpstan-implements Item<T>
 */
final class LazyItem implements Item
{
    /**
     * @var T|null
     */
    private $loadedItem;

    public function __construct(
        private EntityHydrator $hydrator,
        private ClassProperty $relation,
        private int $id,
    ) {
        $this->loadedItem = null;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function get()
    {
        return $this->loadedItem ??= $this->initialize();
    }

    /**
     * @phpstan-return T
     * @return object
     */
    private function initialize()
    {
        $rel = $this->relation->relationshipAttribute;

        if (!($rel instanceof ManyToOne) && !($rel instanceof OneToOne)) {
            throw new LogicException('Expected ManyToOne or OneToOne');
        }

        $tableName = $this->hydrator->getMetadataFactory()->getMetadata($rel->targetEntity)->getTableName();

        /** @phpstan-var T[] $items */
        $items = $this->hydrator->fromStatementArray(
            className: $rel->targetEntity,
            statement: $this->hydrator->getDatabaseConnection()->query(
                "SELECT * FROM $tableName WHERE {$rel->theirColumn} = {$this->id}"
            ),
            reuse: true,
        );

        return $items[0] ?? throw new RuntimeException('Item not found by id ' . $this->id);
    }
}
