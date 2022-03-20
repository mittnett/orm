<?php
declare(strict_types=1);

namespace HbLib\ORM;

interface IdentifiableEntityInterface
{
    /**
     * @throws UnpersistedException
     */
    public function getId(): int;
}
