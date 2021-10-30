<?php
declare(strict_types=1);

namespace HbLib\ORM;

use DateTimeInterface;
use HbLib\DBAL\DatabaseConnectionInterface;
use HbLib\DBAL\Driver\PostgresDriver;
use HbLib\ORM\Attribute\ManyToOne;
use HbLib\ORM\Attribute\OneToMany;
use HbLib\ORM\Attribute\OneToOne;
use HbLib\ORM\Attribute\Property;
use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use WeakMap;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_pop;
use function array_reverse;
use function array_values;
use function count;
use function get_class;
use function implode;
use function is_numeric;
use function reset;
use function str_repeat;

/**
 * Class EntityPersister
 * @package HbLib\Sampar\ORM
 */
class EntityPersister
{
    /**
     * @var WeakMap<object, EntitySnapshot>
     */
    private WeakMap $entityChangesets;

    public function __construct(
        private DatabaseConnectionInterface $databaseConnection,
        private EntityMetadataFactory $metadataFactory,
        private EventDispatcherInterface $eventDispatcher,
    ) {
        $this->entityChangesets = new WeakMap();
    }

    /**
     * Capture the provided entities and store the data so flush can later only update changed fields.
     * There is no need to capture new entities.
     *
     * @phpstan-template T
     * @phpstan-param non-empty-array<T> $tracking
     * @param object[] $tracking
     */
    public function capture(array $tracking): void
    {
        $currentEntitySnapshots = $this->dumpEntity($tracking);

        foreach ($tracking as $entity) {
            $this->entityChangesets[$entity] = $currentEntitySnapshots[$entity];
        }
    }

    /**
     * @phpstan-template T
     * @phpstan-param non-empty-array<T> $entities
     * @param object[] $entities
     */
    public function flush(array $entities): void
    {
        $currentEntityDataStorage = $this->dumpEntity($entities);

        $this->assertSingleEntityInstance($entities);

        $metadata = $this->metadataFactory->getMetadata(get_class($entities[array_key_first($entities)]));
        $idColumn = $metadata->getIdColumn();

        /** @phpstan-var array<int, array{object, EntitySnapshot}> $insertSnapshotsGroup */
        $insertSnapshotsGroup = [];
        /** @phpstan-var array<EntitySnapshot> $updateSnapshots */
        $updateSnapshots = [];

        $reflection = new ReflectionClass($metadata->getClassName());

        $idProperty = $reflection->getProperty($idColumn->name);
        $idProperty->setAccessible(true);

        $metadataProperties = [];
        $allMetadataPropertyKeys = array_keys($metadata->getProperties());

        foreach ($metadata->getProperties() as $key => $metadataProperty) {
            if ($metadataProperty === $idColumn && $idColumn->idAttribute !== null && $idColumn->idAttribute->autoIncrement === true) {
                // ignore auto increment columns
                continue;
            }

            if ($metadataProperty->relationshipAttribute instanceof OneToMany) {
                // OneToMany is never an actual field
                continue;
            }

            if ($metadataProperty->relationshipAttribute !== null && $metadataProperty->relationshipAttribute->ourColumn === null) {
                // relationships with a non-existing metadata property can never work...
                continue;
            }

            $metadataProperties[$key] = $metadataProperty;
        }
        unset($key, $allMetadataPropertyKeys);

        foreach ($entities as $entity) {
            /** @var EntitySnapshot $entitySnapshot */
            $entitySnapshot = $currentEntityDataStorage[$entity];

            if ($idColumn->idAttribute !== null && $idColumn->idAttribute->autoIncrement === true) {
                $isInsert = $entitySnapshot->getId() === null;
            } else {
                // when id column is a relation we must know about the entity before hand.
                $isInsert = $this->entityChangesets->offsetExists($entity) === false;
            }

            if ($isInsert === false) {
                $changedEntityData = [];
                $currentEntityData = $entitySnapshot->getData();

                /** @var EntitySnapshot|null $storedEntitySnapshot */
                $storedEntitySnapshot = $this->entityChangesets[$entity] ?? null;

                if ($storedEntitySnapshot === null) {
                    throw new LogicException('Entity has not been persisted');
                }

                foreach ($storedEntitySnapshot->getData() as $key => $value) {
                    if (array_key_exists($key, $metadataProperties) === true
                        && (array_key_exists($key, $currentEntityData) === false || $value !== $currentEntityData[$key])) {
                        $changedEntityData[$key] = $currentEntityData[$key];
                    }
                }

                unset($currentEntityData, $storedEntitySnapshot);
            } else {
                $changedEntityData = [];

                foreach ($entitySnapshot->getData() as $key => $value) {
                    if (array_key_exists($key, $metadataProperties) === true) {
                        $changedEntityData[$key] = $value;
                    }
                }
            }

            if (count($changedEntityData) === 0) {
                continue;
            }

            $entitySnapshot = new EntitySnapshot($entitySnapshot->getId(), $changedEntityData);
            unset($changedEntityData);

            $this->eventDispatcher->dispatch(new EntityChangeEvent(
                $entity, $entitySnapshot, $isInsert ? EntityChangeEvent::MODE_INSERT : EntityChangeEvent::MODE_UPDATE
            ));

            if ($isInsert === true) {
                $insertSnapshotsGroup[] = [$entity, $entitySnapshot];
            } else {
                $updateSnapshots[] = $entitySnapshot;
            }
        }

        if (count($insertSnapshotsGroup) > 0) {
            $databaseDriver = $this->databaseConnection->getDriver();

            /** @phpstan-var array<string, array<array{object, EntitySnapshot}>> $insertSnapshotsGroupedByNumberOfProperties */
            $insertSnapshotsGroupedByNumberOfProperties = [];

            foreach ($insertSnapshotsGroup as $insertSnapshotGroup) {
                $uniqueGroupId = implode('-_-', array_keys($insertSnapshotGroup[1]->getData())) . '_' . count($insertSnapshotGroup[1]->getData());
                $insertSnapshotsGroupedByNumberOfProperties[$uniqueGroupId][] = $insertSnapshotGroup;
            }
            unset($insertSnapshotsGroup);

            foreach ($insertSnapshotsGroupedByNumberOfProperties as $insertSnapshotPropertyGroup) {
                [, $firstEntitySnapshot] = $insertSnapshotPropertyGroup[array_key_first($insertSnapshotPropertyGroup)];

                $definedPropertyDbNames = array_map(
                    static fn (string $key): string => $databaseDriver->quoteColumn($metadataProperties[$key]->getNameForDb()),
                    array_keys($firstEntitySnapshot->getData()),
                );

                $insertStatementSql = 'INSERT INTO ' . $databaseDriver->quoteColumn($metadata->getTableName()) . '('
                    . implode(', ', $definedPropertyDbNames)
                    . ')VALUES(?' . str_repeat(',?', count($definedPropertyDbNames) - 1) . ')';

                if ($databaseDriver instanceof PostgresDriver) {
                    $insertStatementSql .= ' RETURNING ' . $databaseDriver->quoteColumn($idColumn->getNameForDb());
                }

                $insertPreparedStatement = $this->databaseConnection->prepare($insertStatementSql);

                $definedPropertyNamesCount = count($definedPropertyDbNames);

                foreach ($insertSnapshotPropertyGroup as [$entity, $entitySnapshot]) {
                    if ($definedPropertyNamesCount !== count($entitySnapshot->getData())) {
                        throw new LogicException('Unexpected mismatch in properties count and entity snapshot data count');
                    }

                    $insertPreparedStatement->execute(array_values($entitySnapshot->getData()));

                    if ($idColumn->relationshipAttribute instanceof OneToOne || $idColumn->relationshipAttribute instanceof ManyToOne) {
                        // relation id property, set unloaded item for now.
                        $idProperty->setValue($entity, new UnloadedItem($this->getInsertedId($insertPreparedStatement)));
                    } else {
                        $idProperty->setValue($entity, $this->getInsertedId($insertPreparedStatement));
                    }
                }

                unset($firstEntitySnapshot, $definedPropertyDbNames);
            }

            unset($reflection, $idProperty, $insertSnapshotsGroupedByNumberOfProperties);
        }

        if (count($updateSnapshots) > 0) {
            $databaseDriver = $this->databaseConnection->getDriver();

            $updateSnapshots = array_reverse($updateSnapshots);

            while ($entitySnapshot = array_pop($updateSnapshots)) {
                $updatePreparedStatement = $this->databaseConnection->prepare(
                    'UPDATE ' . $databaseDriver->quoteColumn($metadata->getTableName()) . ' SET '
                    . implode(', ', array_map(
                        static fn (string $key): string => $databaseDriver->quoteColumn($metadataProperties[$key]->getNameForDb()) . ' = ?',
                        array_keys($entitySnapshot->getData()),
                    )) . ' WHERE ' . $databaseDriver->quoteColumn($idColumn->getNameForDb()) . ' = ?',
                );

                $params = array_values($entitySnapshot->getData());
                $params[] = $entitySnapshot->getId();
                $updatePreparedStatement->execute($params);
            }
        }
    }

    private function getInsertedId(\PDOStatement $statement): int|string
    {
        $driver = $this->databaseConnection->getDriver();

        if ($driver instanceof PostgresDriver) {
            return (int) $statement->fetchColumn();
        }

        return $this->databaseConnection->getLastInsertId();
    }

    /**
     * @phpstan-template T
     * @phpstan-param non-empty-array<T> $entities
     * @param object[] $entities
     */
    public function delete(array $entities): void
    {
        $this->assertSingleEntityInstance($entities);

        $metadata = $this->metadataFactory->getMetadata(get_class($entities[array_key_first($entities)]));
        $idColumn = $metadata->getIdColumn();

        $reflectionClass = new ReflectionClass($metadata->getClassName());
        $idProperty = $reflectionClass->getProperty($idColumn->name);
        $idProperty->setAccessible(true);

        $entityIds = array_map(static fn (object $entity): string|int => $idProperty->getValue($entity), $entities);

        $databaseDriver = $this->databaseConnection->getDriver();

        $deleteQuery = $this->databaseConnection->prepare(
            'DELETE FROM ' . $databaseDriver->quoteColumn($metadata->getTableName()) . ' WHERE '
                . $databaseDriver->quoteColumn($idColumn->getNameForDb())
                . ' IN(?' . str_repeat(',?', count($entityIds) - 1) . ')',
        );
        $deleteQuery->execute(array_values($entityIds));
    }

    /**
     * @phpstan-template T
     * @phpstan-param non-empty-array<T> $entities
     * @phpstan-return WeakMap<EntitySnapshot>
     */
    private function dumpEntity(array $entities): WeakMap
    {
        $result = new WeakMap();

        $this->assertSingleEntityInstance($entities);

        $metadata = $this->metadataFactory->getMetadata(get_class($entities[array_key_first($entities)]));
        $reflection = new ReflectionClass($metadata->getClassName());

        $idProperty = $reflection->getProperty($metadata->getIdColumn()->name);
        $idProperty->setAccessible(true);

        $entityProperties = $metadata->getProperties();

        foreach ($entities as $entity) {
            $entityData = [];

            foreach ($entityProperties as $propName => $classProperty) {
                $propertyAttribute = $classProperty->propertyAttribute;
                if ($propertyAttribute === null) {
                    continue;
                }

                if ($propName === $metadata->getIdColumn()->name) continue;

                $relProperty = $reflection->getProperty($propName);
                $relProperty->setAccessible(true);

                if ($classProperty->relationshipAttribute instanceof ManyToOne || $classProperty->relationshipAttribute instanceof OneToOne) {
                    $item = $relProperty->getValue($entity);

                    if ($item === null && $relProperty->getType()?->allowsNull() === true) {
                        $entityData[$propName] = null;
                        continue;
                    }

                    if ($item instanceof LazyItem) {
                        $entityData[$propName] = $item->getId();
                    } else if ($item instanceof UnloadedItem) {
                        $entityData[$propName] = $item->id;
                    } else if ($item instanceof Item) {
                        $entityData[$propName] = $item->get()->getId() ?? throw new LogicException('ID is null!');
                    } else {
                        throw new LogicException('Unhandled ' . $item::class);
                    }

                    continue;
                }

                switch ($propertyAttribute->type) {
                    case Property::TYPE_DATETIME:
                    case Property::TYPE_DATE:
                        $dt = $relProperty->getValue($entity);

                        $entityData[$propName] = $propertyAttribute->dtFormat && $dt instanceof DateTimeInterface ? $dt->format($propertyAttribute->dtFormat) : null;
                        break;

                    case Property::TYPE_BOOL:
                        $entityData[$propName] = $relProperty->getValue($entity) === true ? 1 : 0;
                        break;

                    case Property::TYPE_FLOAT:
                    case Property::TYPE_INT:
                        $float = $relProperty->getValue($entity);

                        if (is_numeric($float) === true) {
                            $entityData[$propName] = $propertyAttribute->type === Property::TYPE_FLOAT
                                ? (float)$float
                                : (int)$float;
                        } else {
                            $entityData[$propName] = null;
                        }
                        break;

                    default:
                        $entityData[$propName] = $relProperty->getValue($entity);
                        break;
                }
            }

            $id = null;

            if ($idProperty->isInitialized($entity) === true) {
                $id = $idProperty->getValue($entity);
            }

            if ($id instanceof Item) {
                // id is an item, get the ID of it and set to data.
                $entityData[$idProperty->name] = $id->getId();
                $id = $id->getId();
            }

            if ((is_string($id) === false || $id === '') && is_int($id) === false && $id !== null) {
                throw new LogicException('ID must be string, int, or null, it was: ' . gettype($id));
            }

            $result[$entity] = new EntitySnapshot($id, $entityData);
        }

        return $result;
    }

    /**
     * @template T
     * @param non-empty-array<T> $entities
     */
    private function assertSingleEntityInstance(array $entities): void
    {
        $firstEntity = $entities[array_key_first($entities)];

        if ($firstEntity instanceof Collection) {
            throw new LogicException('Cant dump collection of entities, please provide an entity');
        }

        $entityClassName = get_class($firstEntity);

        foreach ($entities as $entity) {
            if ($entity instanceof Item) {
                $entity = $entity->get();
            }

            if (!($entity instanceof $entityClassName)) {
                throw new LogicException('Multiple entities found!');
            }
        }
    }
}
