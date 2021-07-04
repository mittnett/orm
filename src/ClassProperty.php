<?php
declare(strict_types=1);

namespace HbLib\ORM;

use HbLib\ORM\Attribute\Id;
use HbLib\ORM\Attribute\Property;
use HbLib\ORM\Attribute\Relationship;

class ClassProperty
{
    public function __construct(
        public string $name,
        public ?Property $propertyAttribute,
        public ?Relationship $relationshipAttribute = null,
        public ?Id $idAttribute = null,
    ) {
    }

    public function getNameForDb(): string
    {
        return $this->propertyAttribute->name ?? $this->name;
    }
}
