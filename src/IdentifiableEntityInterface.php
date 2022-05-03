<?php
declare(strict_types=1);

namespace HbLib\ORM;

interface IdentifiableEntityInterface
{
    public function getId(): ?int;
}
