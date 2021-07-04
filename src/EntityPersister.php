<?php
declare(strict_types=1);

namespace HbLib\ORM;

use DateTimeInterface;
use HbLib\DBAL\DatabaseConnectionInterface;
use HbLib\ORM\Attribute\ManyToOne;
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

        $entityClassName = get_class($entities[array_key_first($entities)]);
        $metadata = $this->metadataFactory->getMetadata($entityClassName);

        $idColumn = $metadata->getIdColumn();

        foreach ($entities as $entity) {
            if (!($entity instanceof $entityClassName)) {
                throw new LogicException('Multiple entities found!');
            }
        }

        /** @phpstan-var array<int, array{object, EntitySnapshot}> $insertSnapshotsGroup */
        $insertSnapshotsGroup = [];
        /** @phpstan-var array<EntitySnapshot> $updateSnapshots */
        $updateSnapshots = [];

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
                    if (array_key_exists($key, $currentEntityData) === false || $value !== $currentEntityData[$key]) {
                        $changedEntityData[$key] = $currentEntityData[$key];
                    }
                }

                unset($currentEntityData, $storedEntitySnapshot);
            } else {
                $changedEntityData = $entitySnapshot->getData();
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

        $reflection = new ReflectionClass($metadata->getClassName());

        $idProperty = $reflection->getProperty($idColumn->name);
        $idProperty->setAccessible(true);

        $metadataProperties = $metadata->getProperties();

        if ($idColumn->idAttribute !== null && $idColumn->idAttribute->autoIncrement === true) {
            // when class property is not a relation we must not insert/update the column.
            unset($metadataProperties[$idColumn->name]);
        }

        if (count($insertSnapshotsGroup) > 0) {
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
                    static fn (string $key): string => $metadataProperties[$key]->getNameForDb(),
                    array_keys($firstEntitySnapshot->getData())
                );

                $insertPreparedStatement = $this->databaseConnection->prepare(
                    'INSERT INTO ' . $metadata->getTableName() . '(' . implode(', ', $definedPropertyDbNames)
                    . ')VALUES(?' . str_repeat(',?', count($definedPropertyDbNames) - 1) . ')',
                );

                $definedPropertyNamesCount = count($definedPropertyDbNames);

                foreach ($insertSnapshotPropertyGroup as [$entity, $entitySnapshot]) {
                    if ($definedPropertyNamesCount !== count($entitySnapshot->getData())) {
                        throw new LogicException('Unexpected mismatch in properties count and entity snapshot data count');
                    }

                    $insertPreparedStatement->execute(array_values($entitySnapshot->getData()));

                    if ($idColumn->relationshipAttribute instanceof OneToOne || $idColumn->relationshipAttribute instanceof ManyToOne) {
                        // relation id property, set unloaded item for now.
                        $idProperty->setValue($entity, new UnloadedItem((int) $this->databaseConnection->getLastInsertId()));
                    } else {
                        $idProperty->setValue($entity, $this->databaseConnection->getLastInsertId());
                    }
                }

                unset($firstEntitySnapshot, $definedPropertyDbNames);
            }

            unset($reflection, $idProperty, $insertSnapshotsGroupedByNumberOfProperties);
        }

        if (count($updateSnapshots) > 0) {
            $updateSnapshots = array_reverse($updateSnapshots);

            while ($entitySnapshot = array_pop($updateSnapshots)) {
                $updatePreparedStatement = $this->databaseConnection->prepare(
                    'UPDATE ' . $metadata->getTableName() . ' SET '
                    . implode(', ', array_map(
                        static fn (string $key): string => '`' . $metadataProperties[$key]->getNameForDb() . '` = ?',
                        array_keys($entitySnapshot->getData()),
                    )) . ' WHERE `id` = ?',
                );

                $params = array_values($entitySnapshot->getData());
                $params[] = $entitySnapshot->getId();
                $updatePreparedStatement->execute($params);
            }
        }
    }

    /**
     * @phpstan-template T
     * @phpstan-param non-empty-array<T> $entities
     * @phpstan-return WeakMap<EntitySnapshot>
     */
    private function dumpEntity(array $entities): WeakMap
    {
        $result = new WeakMap();

        $firstEntity = $entities[array_key_first($entities)];
        $entityClassName = get_class($firstEntity);
        foreach ($entities as $entity) {
            if (!($entity instanceof $entityClassName)) {
                throw new LogicException('Multiple entities found!');
            }
        }

        if ($firstEntity instanceof Collection || $firstEntity instanceof Item) {
            throw new LogicException('Cant dump collections or lazy items, please provide an entity');
        }

        $metadata = $this->metadataFactory->getMetadata($entityClassName);
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

            $id = $idProperty->getValue($entity);

            if ($id instanceof Item) {
                // id is an item, get the ID of it and set to data.
                $entityData[$idProperty->name] = $id->getId();
                $id = $id->getId();
            }

            $result[$entity] = new EntitySnapshot($id, $entityData);
        }

        return $result;
    }
}
