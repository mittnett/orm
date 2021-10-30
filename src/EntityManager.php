<?php
declare(strict_types=1);

namespace HbLib\ORM;

use HbLib\DBAL\DatabaseConnectionInterface;
use LogicException;
use function array_values;
use function count;
use function reset;
use function str_repeat;

/**
 * Class EntityManager
 * @package HbLib\Sampar\ORM
 */
class EntityManager implements EntityManagerInterface
{
    private \WeakMap $entityMap;

    public function __construct(
        private DatabaseConnectionInterface $databaseConnection,
        private EntityMetadataFactory $metadataFactory,
        private EntityHydrator $hydrator,
        private EntityPersister $persister,
    ) {
        //
    }

    /**
     * @template T
     * @param class-string<T> $className
     * @param int $id
     * @param bool $forUpdateLock
     * @return T|null
     */
    public function find(string $className, int $id, bool $forUpdateLock = false)
    {
        $result = $this->findById($className, [$id], $forUpdateLock);

        return reset($result) ?: null;
    }

    public function lockForUpdate(IdentifiableEntityInterface $object): void
    {
        $this->find($object::class, ($object->getId() ?? throw new LogicException('id is null')), true);
    }

    /**
     * @template T
     * @param class-string<T> $className
     * @param int[] $ids
     * @param bool $forUpdateLock
     * @return T[]
     */
    public function findById(string $className, array $ids, bool $forUpdateLock = false)
    {
        if (count($ids) === 0) {
            return [];
        }

        $metadata = $this->metadataFactory->getMetadata($className);

        $sql = 'SELECT * FROM ' . $metadata->getTableName() . '
        WHERE ' . $metadata->getIdColumn()->getNameForDb() . ' IN(?' . str_repeat(',?', count($ids) - 1) . ')';
        if ($forUpdateLock === true) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->databaseConnection->prepare($sql);
        $stmt->execute(array_values($ids));

        return $this->hydrator->fromStatementArray(className: $className, statement: $stmt, reuse: true);
    }

    /**
     * @return DatabaseConnectionInterface
     */
    public function getDatabaseConnection(): DatabaseConnectionInterface
    {
        return $this->databaseConnection;
    }

    public function beginTransaction(): void
    {
        $this->databaseConnection->beginTransaction();
    }

    public function rollBack(): void
    {
        $this->databaseConnection->rollBack();
    }

    public function commit(): void
    {
        $this->databaseConnection->commit();
    }

    public function getHydrator(): EntityHydrator
    {
        return $this->hydrator;
    }

    public function getPersister(): EntityPersister
    {
        return $this->persister;
    }

    public function getMetadataFactory(): EntityMetadataFactory
    {
        return $this->metadataFactory;
    }

    /**
     * {@inheritDoc}
     */
    public function capture(array $entities): void
    {
        $entitiesByClassName = [];

        foreach ($entities as $entity) {
            $this->entityMap->offsetSet($entity, true);
            $entitiesByClassName[$entity::class][] = $entity;
        }

        foreach ($entitiesByClassName as $entitiesGrouped) {
            if (count($entitiesGrouped) > 0) {
                $this->persister->capture($entitiesGrouped);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function delete(array $entities): void
    {
        foreach ($this->groupEntitiesByClassName($entities) as $entitiesGrouped) {
            if (count($entitiesGrouped) > 0) {
                $this->persister->delete($entitiesGrouped);
            }
        }

        foreach ($entities as $entity) {
            $this->entityMap->offsetUnset($entity);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function flush(?array $entities = null): void
    {
        $providedEntities = $entities;

        if ($providedEntities === null) {
            $entities = [];

            foreach ($this->entityMap as $entity => $_value) {
                $entities[] = $entity;
            }
        }

        foreach ($this->groupEntitiesByClassName($entities) as $entitiesGrouped) {
            if (count($entitiesGrouped) > 0) {
                $this->persister->flush($entitiesGrouped);

                if ($providedEntities === null) {
                    $this->persister->capture($entitiesGrouped);
                }
            }
        }
    }

    /**
     * @template T
     * @param array<T> $entities
     * @return array<string, array<T>>
     */
    private function groupEntitiesByClassName(array $entities): array
    {
        $entitiesByClassName = [];

        foreach ($entities as $entity) {
            $entitiesByClassName[$entity::class][] = $entity;
        }

        return $entitiesByClassName;
    }
}
