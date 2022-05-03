<?php
declare(strict_types=1);

namespace HbLib\ORM;

class EntityPersistBeforeDumpEvent
{
    public function __construct(
        public readonly object $entity,
    ) {
        //
    }
}

