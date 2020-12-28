<?php
declare(strict_types=1);

namespace HbLib\ORM;

use HbLib\ORM\Attribute\OneToOne;
use LogicException;

/**
 * Class ArrayCollection
 * @package HbLib\ORM
 * @phpstan-template T
 * @phpstan-extends ArrayCollection<T>
 */
class LazyCollection extends ArrayCollection
{
    private bool $isInitialized;
    private bool $hasChanged;

    public function __construct(
        private EntityHydrator $hydrator,
        private ClassPropertyRelation $relation,
        private int $id,
    ) {
        parent::__construct([]);

        $this->hasChanged = false;
        $this->isInitialized = false;
    }

    public function add($object): bool
    {
        if ($this->isInitialized === false) {
            $this->items = $this->initialize();
            $this->isInitialized = true;
        }

        $this->hasChanged = true;

        return parent::add($object);
    }

    public function contains($object): bool
    {
        if ($this->isInitialized === false) {
            $this->items = $this->initialize();
            $this->isInitialized = true;
        }

        return parent::contains($object);
    }

    public function remove($object): bool
    {
        if ($this->isInitialized === false) {
            $this->items = $this->initialize();
            $this->isInitialized = true;
        }

        $this->hasChanged = true;

        return parent::remove($object);
    }

    public function getIterator()
    {
        if ($this->isInitialized === false) {
            $this->items = $this->initialize();
            $this->isInitialized = true;
        }

        return parent::getIterator();
    }

    public function count()
    {
        if ($this->isInitialized === false) {
            $this->items = $this->initialize();
            $this->isInitialized = true;
        }

        return parent::count();
    }

    /**
     * @return T[]
     */
    private function initialize()
    {
        $rel = $this->relation->relationship;

        if ($rel instanceof OneToOne) {
            throw new LogicException('Unexpected OneToOne');
        }

        $tableName = $this->hydrator->getMetadataFactory()->getMetadata($rel->targetEntity)->getTableName();

        /** @phpstan-var array<int, T> $items */
        $items = $this->hydrator->fromStatementArray(
            className: $rel->targetEntity,
            statement: $this->hydrator->getDatabaseConnection()->query(
                "SELECT * FROM $tableName WHERE {$rel->theirColumn} = {$this->id}"
            ),
            reuse: true,
        );

        return $items;
    }
}
