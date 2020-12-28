<?php
declare(strict_types=1);

namespace HbLib\ORM;

class EntityChangeEvent
{
    public const MODE_INSERT = 'insert';
    public const MODE_UPDATE = 'update';

    /**
     * EntityChangeEvent constructor.
     * @param object $entity
     * @param EntitySnapshot $change
     * @phpstan-param self::MODE_* $mode
     * @param string $mode
     */
    public function __construct(
        public object $entity,
        public EntitySnapshot $change,
        public string $mode,
    ) {
        //
    }
}
