<?php
declare(strict_types=1);

namespace HbLib\ORM;

/**
 * Interface Item
 * @package HbLib\ORM
 * @phpstan-template-covariant T of IdentifiableEntityInterface
 */
interface Item
{
    /**
     * @return T
     */
    public function get();

    /**
     * @throws UnpersistedException
     */
    public function getId(): int;
}
