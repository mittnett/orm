<?php
declare(strict_types=1);

namespace HbLib\ORM;

use DateTimeImmutable;
use Generator;
use HbLib\DBAL\DatabaseConnectionInterface;
use HbLib\ORM\Attribute as HbLibAttrs;
use LogicException;
use PDOStatement;
use ReflectionClass;
use WeakMap;
use WeakReference;
use function array_key_exists;
use function count;
use function is_numeric;
use function iterator_to_array;

/**
 * Class EntityHydrator
 * @package HbLib\Sampar\ORM
 */
class EntityHydrator
{
    /**
     * @phpstan-var array<string, WeakReference<object>>
     * @var WeakReference[]
     */
    private array $entityReferences;

    /**
     * @phpstan-var array<string, WeakReference<object>>
     * @var WeakReference[]
     */
    private array $lazyItemsCache;

    public function __construct(
        private DatabaseConnectionInterface $databaseConnection,
        private EntityMetadataFactory $metadataFactory
    ) {
        $this->entityReferences = [];
        $this->lazyItemsCache = [];
    }

    /**
     * @phpstan-template T
     * @phpstan-param class-string<T> $className
     * @phpstan-param PDOStatement<array<mixed, mixed>> $statement
     * @phpstan-return array<int, T>
     * @return array
     */
    public function fromStatementArray(string $className, PDOStatement $statement, string $indexBy = null, bool $reuse = false): array
    {
        return iterator_to_array($this->fromStatement($className, $statement, $indexBy, $reuse));
    }

    /**
     * @phpstan-template T
     * @phpstan-param class-string<T> $className
     * @phpstan-param PDOStatement<array<mixed, mixed>> $statement
     * @phpstan-return Generator<int, T, null, void>
     * @return Generator
     */
    public function fromStatement(string $className, PDOStatement $statement, string $indexBy = null, bool $reuse = false): Generator
    {
        return $this->fromIterable($className, $this->getRowsIteratorFromStatement($statement), $indexBy, $reuse);
    }

    /**
     * @phpstan-param PDOStatement<array<mixed, mixed>> $statement
     * @param PDOStatement $statement
     * @phpstan-return Generator<int, mixed, null, void>
     * @return Generator
     */
    private function getRowsIteratorFromStatement(PDOStatement $statement): Generator
    {
        while ($row = $statement->fetch()) {
            yield $row;
        }
    }

    /**
     * @phpstan-template T
     * @phpstan-param class-string<T> $className
     * @phpstan-param iterable<array<mixed, mixed>> $rows
     * @phpstan-return Generator<int, T, null, void>
     * @return Generator
     */
    public function fromIterable(string $className, iterable $rows, string $indexBy = null, bool $reuse = false): Generator
    {
        $metadata = $this->metadataFactory->getMetadata($className);
        $idColumn = $metadata->getIdColumn();
        $reflection = new ReflectionClass($className);
        $entityProperties = $metadata->getProperties();

        if (count($entityProperties) === 0) {
            throw new LogicException('Entity properties are empty');
        }

        /** @phpstan-var array<string, DateTimeImmutable> $immutableDateTimeCache */
        $immutableDateTimeCache = [];

        foreach ($rows as $row) {
            $entityId = $className . '_' . $row[$idColumn->getNameForDb()];

            if ($reuse === true && array_key_exists($entityId, $this->entityReferences) === true) {
                $ref = $this->entityReferences[$entityId]->get();

                if ($ref instanceof $className) {
                    if ($indexBy !== null) {
                        yield $row[$indexBy] => $ref;
                    } else {
                        yield $ref;
                    }
                    continue;
                }

                unset($this->entityReferences[$entityId], $ref);
            }

            /** @phpstan-var T $classInstance */
            $classInstance = $reflection->newInstanceWithoutConstructor();

            foreach ($entityProperties as $propertyNameKeyed => $property) {
                $propertyName = $property->propertyAttribute?->name ?? $propertyNameKeyed;

                $reflProperty = $reflection->getProperty($propertyNameKeyed);
                $reflProperty->setAccessible(true);

                // if the row is not defined and we allow null in PHP _THEN_ this can be unset.
                if (isset($row[$propertyName]) === false && $reflProperty->getType()?->allowsNull() === true) {
                    $reflProperty->setValue($classInstance, null);
                    continue;
                }

                if ($property->relationshipAttribute !== null) {
                    // handle the relationship present on this property. Note that the property might also be a database column.

                    if ($property->relationshipAttribute instanceof HbLibAttrs\ManyToOne || $property->relationshipAttribute instanceof HbLibAttrs\OneToOne) {
                        // we are loading an Item here.
                        $relationId = (int) $row[$propertyName];
                        $cacheKey = "{$property->relationshipAttribute->targetEntity}_{$relationId}";

                        if (array_key_exists($cacheKey, $this->lazyItemsCache) === true) {
                            $lazyItem = $this->lazyItemsCache[$cacheKey]->get();

                            if ($lazyItem instanceof LazyItem) {
                                $reflProperty->setValue($classInstance, $lazyItem);
                                continue;
                            }
                        }

                        $lazyItem = new LazyItem($this, $property, $relationId);
                        $this->lazyItemsCache[$cacheKey] = WeakReference::create($lazyItem);

                        $reflProperty->setValue($classInstance, $lazyItem);
                    } else if ($property->relationshipAttribute instanceof HbLibAttrs\OneToMany) {
                        $reflProperty->setValue($classInstance, new LazyCollection(
                            hydrator: $this,
                            relation: $property,
                            id: (int) $row[$property->relationshipAttribute->ourColumn],
                        ));
                    } else {
                        throw new LogicException('Unhandled relationship instance ' . $property->relationshipAttribute::class);
                    }
                    continue;
                }

                $propertyAttribute = $property->propertyAttribute;

                if ($propertyAttribute === null) {
                    continue;
                }

                switch ($propertyAttribute->type) {
                    case HbLibAttrs\Property::TYPE_DATETIME:
                    case HbLibAttrs\Property::TYPE_DATE:
                        $value = $row[$propertyName];

                        if ($value === null) {
                            $reflProperty->setValue($classInstance, null);
                            continue 2;
                        }

                        $dateTime = $immutableDateTimeCache[$value] ??= new DateTimeImmutable($value);

                        if ($propertyAttribute->type === HbLibAttrs\Property::TYPE_DATE) {
                            $dateTime = $dateTime->setTime(0, 0);
                        }

                        $reflProperty->setValue($classInstance, $dateTime);
                        break;

                    case HbLibAttrs\Property::TYPE_ENUM:
                        if (function_exists('enum_exists') === false) {
                            throw new \RuntimeException('Enum can only be used with php 8.1. (enum_exists function do not exist)');
                        }

                        $reflPropertyType = $reflProperty->getType();
                        $enumClassName = $reflPropertyType instanceof \ReflectionNamedType
                            ? $reflPropertyType->getName()
                            : null;

                        if (is_string($enumClassName) === true && enum_exists($enumClassName) === true) {
                            $enumFromCallable = \Closure::fromCallable([$enumClassName, 'from']);
                            $enum = $enumFromCallable($row[$propertyName]);
                            $reflProperty->setValue($classInstance, $enum);
                            break;
                        }

                        throw new \LogicException('Failed to hydrate enum');

                    case HbLibAttrs\Property::TYPE_BOOL:
                        $reflProperty->setValue($classInstance, $row[$propertyName] == 1 ? true : false);
                        break;

                    case HbLibAttrs\Property::TYPE_FLOAT:
                    case HbLibAttrs\Property::TYPE_INT:
                        if (is_numeric($row[$propertyName]) === true) {
                            $reflProperty->setValue($classInstance, $propertyAttribute->type === HbLibAttrs\Property::TYPE_FLOAT
                                ? (float) $row[$propertyName]
                                : (int) $row[$propertyName]);
                        } else {
                            $reflProperty->setValue($classInstance, null);
                        }
                        break;

                    default:
                        $reflProperty->setValue($classInstance, $row[$propertyName]);
                        break;
                }
            }

            $this->entityReferences[$entityId] = WeakReference::create($classInstance);

            if ($indexBy !== null) {
                yield $row[$indexBy] => $classInstance;
            } else {
                yield $classInstance;
            }
        }
    }

    public function getDatabaseConnection(): DatabaseConnectionInterface
    {
        return $this->databaseConnection;
    }

    public function getMetadataFactory(): EntityMetadataFactory
    {
        return $this->metadataFactory;
    }
}
