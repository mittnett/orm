<?php
declare(strict_types=1);

namespace HbLib\ORM;

use Exception;

class UnpersistedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Entity has no ID');
    }
}
