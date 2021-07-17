<?php
declare(strict_types=1);

namespace HbLib\ORM\Attribute;

abstract class Relationship
{
    /**
     * Relationship constructor.
     * @phpstan-param class-string $targetEntity
     */
    public function __construct(
        public string $targetEntity,
        public string $theirColumn,
        public ?string $ourColumn,
    ) {
    }
}
