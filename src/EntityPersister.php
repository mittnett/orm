<?php
declare(strict_types=1);

namespace HbLib\ORM;

use DateTimeInterface;
use HbLib\DBAL\DatabaseConnectionInterface;
use HbLib\ORM\Attribute\ManyToOne;
use HbLib\ORM\Attribute\OneToOne;
use HbLib\ORM\Attribute\Property;
use HbLib\ORM\EntityChangeEvent;
use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use WeakMap;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_pop;
use function array_reverse;
use function array_unique;
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
     * @phpstan-param T[] $tracking
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
     * @phpstan-param T[] $entities
     * @param object[] $entities
     */
    public function flush(array $entities): void
    {
        if (count($entities) === 0) {
            return;
        }

        $currentEntityDataStorage = $this->dumpEntity($entities);

        $entityClassName = get_class(reset($entities));
        $metadata = $this->metadataFactory->getMetadata($entityClassName);

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

            if ($entitySnapshot->getId() !== null) {
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

            $isInsert = $entitySnapshot->getId() === null;
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
            $reflection = new ReflectionClass($metadata->getClassName());

            $idProperty = $reflection->getProperty($metadata->getIdColumn());
            $idProperty->setAccessible(true);

            $properties = $metadata->getProperties();
            unset($properties[$metadata->getIdColumn()]);

            $propertyNames = array_map(static fn (ClassProperty $property): string => "`{$property->getNameForDb()}`", $properties);
            $insertPreparedStatement = $this->databaseConnection->prepare(
                'INSERT INTO ' . $metadata->getTableName() . '(' . implode(', ', $propertyNames)
                . ')VALUES(?' . str_repeat(',?', count($properties) - 1) . ')',
            );

            $insertSnapshotsGroup = array_reverse($insertSnapshotsGroup);
            $propertiesCount = count($properties);

            while ([$entity, $entitySnapshot] = array_pop($insertSnapshotsGroup)) {
                if ($propertiesCount !== count($entitySnapshot->getData())) {
                    throw new LogicException('Unexpected mismatch in properties count and entity snapshot data count');
                }

                $insertPreparedStatement->execute(array_values($entitySnapshot->getData()));
                $idProperty->setValue($entity, $this->databaseConnection->getLastInsertId());
            }

            unset($reflection, $idProperty, $propertyNames);
        }

        if (count($updateSnapshots) > 0) {
            $updateSnapshots = array_reverse($updateSnapshots);

            while ($entitySnapshot = array_pop($updateSnapshots)) {
                $updatePreparedStatement = $this->databaseConnection->prepare(
                    'UPDATE ' . $metadata->getTableName() . ' SET '
                    . implode(', ', array_map(static fn (string $key): string => "`$key` = ?", array_keys($entitySnapshot->getData())))
                    . ' WHERE `id` = ?',
                );

                $params = array_values($entitySnapshot->getData());
                $params[] = $entitySnapshot->getId();
                $updatePreparedStatement->execute($params);
            }
        }
    }

    /**
     * @phpstan-template T
     * @phpstan-param T[] $entities
     * @phpstan-return WeakMap<EntitySnapshot>
     */
    private function dumpEntity(array $entities): WeakMap
    {
        $result = new WeakMap();

        if (count($entities) === 0) {
            return $result;
        }

        $firstEntity = reset($entities);
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

        $idProperty = $reflection->getProperty($metadata->getIdColumn());
        $idProperty->setAccessible(true);

        $entityProperties = $metadata->getProperties();

        foreach ($entities as $entity) {
            $entityData = [];

            $id = $idProperty->getValue($entity);

            foreach ($entityProperties as $propName => $classProperty) {
                $propertyAttribute = $classProperty->propertyAttribute;
                if ($propertyAttribute === null) {
                    continue;
                }

                $propertyName = $propertyAttribute->name ?? $propName;
                if ($propertyName === $metadata->getIdColumn()) continue;

                $relProperty = $reflection->getProperty($propName);
                $relProperty->setAccessible(true);

                if ($classProperty instanceof ClassPropertyRelation) {
                    if ($classProperty->relationship instanceof ManyToOne || $classProperty->relationship instanceof OneToOne) {
                        $item = $relProperty->getValue($entity);

                        if ($item === null && $relProperty->getType()?->allowsNull() === true) {
                            $entityData[$propertyName] = null;
                            continue;
                        }

                        if ($item instanceof LazyItem) {
                            $entityData[$propertyName] = $item->getId();
                        } else if ($item instanceof UnloadedItem) {
                            $entityData[$propertyName] = $item->id;
                        } else if ($item instanceof Item) {
                            $entityData[$propertyName] = $item->get()->getId() ?? throw new LogicException('ID is null!');
                        } else {
                            throw new LogicException('Unhandled ' . $item::class);
                        }
                    }
                    continue;
                }

                switch ($propertyAttribute->type) {
                    case Property::TYPE_DATETIME:
                    case Property::TYPE_DATE:
                        $dt = $relProperty->getValue($entity);

                        $entityData[$propertyName] = $propertyAttribute->dtFormat && $dt instanceof DateTimeInterface ? $dt->format($propertyAttribute->dtFormat) : null;
                        break;

                    case Property::TYPE_BOOL:
                        $entityData[$propertyName] = $relProperty->getValue($entity) === true ? 1 : 0;
                        break;

                    case Property::TYPE_FLOAT:
                    case Property::TYPE_INT:
                        $float = $relProperty->getValue($entity);

                        if (is_numeric($float) === true) {
                            $entityData[$propertyName] = $propertyAttribute->type === Property::TYPE_FLOAT
                                ? (float)$float
                                : (int)$float;
                        } else {
                            $entityData[$propertyName] = null;
                        }
                        break;

                    default:
                        $entityData[$propertyName] = $relProperty->getValue($entity);
                        break;
                }
            }

            $result[$entity] = new EntitySnapshot($id, $entityData);
        }

        return $result;
    }
}
