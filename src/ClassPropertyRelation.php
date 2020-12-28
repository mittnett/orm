<?php
declare(strict_types=1);

namespace HbLib\ORM;


use HbLib\ORM\Attribute\Property;
use HbLib\ORM\Attribute\Relationship;

class ClassPropertyRelation extends ClassProperty
{
    public function __construct(
        string $name,
        ?Property $property,
        public Relationship $relationship,
    ) {
        parent::__construct($name, $property);
    }
}
