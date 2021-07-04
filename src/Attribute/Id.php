<?php
declare(strict_types=1);

namespace HbLib\ORM\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Id
{
    public function __construct(
        public bool $autoIncrement = true,
    ) {
        //
    }
}
