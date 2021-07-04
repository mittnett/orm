<?php
declare(strict_types=1);

namespace HbLib\ORM;

use HbLib\ORM\Attribute as HbLibAttrs;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use WeakReference;
use function array_key_exists;
use function count;

class EntityMetadataFactory
{
    /**
     * @phpstan-var array<class-string, WeakReference<EntityMetadata<mixed>>>
     */
    private array $cache;

    public function __construct()
    {
        $this->cache = [];
    }

    /**
     * @phpstan-template T
     * @phpstan-param class-string<T> $className
     * @phpstan-return EntityMetadata<T>
     */
    public function getMetadata(string $className): EntityMetadata
    {
        if (array_key_exists($className, $this->cache) === true) {
            $metadata = $this->cache[$className]->get();

            if ($metadata !== null) {
                return $metadata;
            }
        }

        $metadata = $this->createMetadata($className);
        $this->cache[$className] = WeakReference::create($metadata);

        return $metadata;
    }

    /**
     * @phpstan-template T
     * @phpstan-param class-string<T> $className
     * @phpstan-return EntityMetadata<T>
     */
    private function createMetadata(string $className): EntityMetadata
    {
        $reflection = new ReflectionClass($className);

        $classProperties = [];

        /** @var ClassProperty|null $idColumn */
        $idColumn = null;

        foreach ($reflection->getProperties() as $property) {
            /** @phpstan-var array{
             * name: string,
             * property: \HbLib\ORM\Attribute\Property|null,
             * relationship: \HbLib\ORM\Attribute\Relationship|null,
             * } $classPropertyFactory
             */
            $classPropertyFactory = [
                'name' => $property->getName(),
                'property' => null,
                'relationship' => null,
            ];

            $entityPropertyAttributes = $property->getAttributes(HbLibAttrs\Property::class);

            if (count($entityPropertyAttributes) > 0) {
                $attr = $entityPropertyAttributes[0]->newInstance();

                if (!($attr instanceof HbLibAttrs\Property)) {
                    throw new LogicException('invalid instance');
                }

                $classPropertyFactory['property'] = $attr;
            }

            $relationshipProperties = $property->getAttributes(HbLibAttrs\Relationship::class, ReflectionAttribute::IS_INSTANCEOF);

            if (count($relationshipProperties) > 0) {
                $relationshipAttribute = $relationshipProperties[0]->newInstance();

                if (!($relationshipAttribute instanceof HbLibAttrs\Relationship)) {
                    throw new LogicException('invalid instance');
                }

                $classPropertyFactory['relationship'] = $relationshipAttribute;
            }

            if ($classPropertyFactory['relationship'] === null) {
                $classProperties[$classPropertyFactory['name']] = new ClassProperty($classPropertyFactory['name'], $classPropertyFactory['property']);
            } else {
                $classProperties[$classPropertyFactory['name']] = new ClassPropertyRelation(
                    $classPropertyFactory['name'],
                    $classPropertyFactory['property'],
                    $classPropertyFactory['relationship'],
                );
            }

            if ($classPropertyFactory['property'] !== null && count($property->getAttributes(HbLibAttrs\Id::class)) > 0) {
                $idColumn = $classProperties[$classPropertyFactory['name']];
            }
        }

        $entityAttributeReflection = $reflection->getAttributes(HbLibAttrs\Entity::class);
        if (count($entityAttributeReflection) !== 1) {
            throw new LogicException('Unable to find entity attribute');
        }

        $entityAttribute = $entityAttributeReflection[0]->newInstance();

        if (!($entityAttribute instanceof HbLibAttrs\Entity)) {
            throw new LogicException('invalid attribute instance');
        }

        if ($idColumn === null) {
            throw new LogicException('idColumn is null');
        }

        $metadata = new EntityMetadata(
            className: $className,
            tableName: $entityAttribute->table,
            idColumn: $idColumn,
            properties: $classProperties,
        );

        return $metadata;
    }
}
