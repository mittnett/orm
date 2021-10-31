<?php

declare(strict_types=1);

namespace HbLib\ORM\Tests;

/**
 * @author Edvin Hultberg <edvin@hultberg.no>
 */
class TestPDOStatement extends \PDOStatement
{
    /**
     * @var array<string, mixed>
     */
    private array $parameters;

    public function __construct(string $queryString)
    {
        $this->queryString = $queryString;
        $this->parameters = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function execute($params = null): bool
    {
        if ($params !== null) {
            $this->parameters = $params;
        }

        return parent::execute($params);
    }

    public function bindColumn($column, &$var, $type = null, $maxLength = null, $driverOptions = null): bool
    {
        throw new \LogicException('Unimplemented');
    }

    public function bindParam($param, &$var, $type = \PDO::PARAM_STR, $maxLength = null, $driverOptions = null): bool
    {
        $this->parameters[$param] = &$var;

        return parent::bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    public function bindValue($param, $value, $type = \PDO::PARAM_STR): bool
    {
        $this->parameters[$param] = $value;

        return parent::bindValue($param, $value, $type);
    }
}
