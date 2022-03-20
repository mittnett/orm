<?php
declare(strict_types=1);

namespace HbLib\ORM;

/**
 * Interface Item
 * @package HbLib\ORM
 */
interface Item extends IdentifiableEntityInterface
{
    /**
     * @return T
     */
    public function get();
}
