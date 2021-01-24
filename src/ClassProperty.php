<?php
declare(strict_types=1);

namespace HbLib\ORM;

use HbLib\ORM\Attribute\Property;

class ClassProperty
{
    public function __construct(
        public string $name,
        public ?Property $propertyAttribute,
    ) {
    }

    public function getNameForDb(): string
    {
        return $this->propertyAttribute->name ?? $this->name;
    }
}
