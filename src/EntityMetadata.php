<?php
declare(strict_types=1);

namespace HbLib\ORM;

use HbLib\ORM\Attribute as HbLibAttrs;

/**
 * Class EntityMetadata
 * @package HbLib\Sampar\ORM
 * @phpstan-template T
 */
class EntityMetadata
{
    /**
     * EntityMetadata constructor.
     * @phpstan-param class-string<T> $className
     * @param string $className
     * @param string $tableName
     * @param ClassProperty $idColumn
     * @param array<string, ClassProperty> $properties
     */
    public function __construct(
        private string $className,
        private string $tableName,
        private ClassProperty $idColumn,
        private array $properties,
    ) {
        //
    }

    /**
     * @phpstan-return class-string<T>
     * @return string
     */
    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * @return array<string, ClassProperty>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getIdColumn(): ClassProperty
    {
        return $this->idColumn;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }
}
