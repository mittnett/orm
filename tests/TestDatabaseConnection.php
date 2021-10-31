<?php

declare(strict_types=1);

namespace HbLib\ORM\Tests;

use HbLib\DBAL\DatabaseConnectionInterface;
use HbLib\DBAL\Driver\DriverInterface;

/**
 * @author Edvin Hultberg <edvin@hultberg.no>
 */
class TestDatabaseConnection implements DatabaseConnectionInterface
{
    /**
     * @var array<TestPDOStatement>
     */
    private array $queries;

    public function __construct()
    {
        $this->queries = [];
    }

    public function getDriver(): DriverInterface
    {
        throw new \LogicException('Unimplemented');
    }

    /**
     * Execute a SQL query.
     *
     * @param string $query
     * @return TestPDOStatement<mixed>
     */
    public function query(string $query): TestPDOStatement
    {
        return new TestPDOStatement($query);
    }

    /**
     * Create a prepared statement.
     *
     * @param string $query
     * @return TestPDOStatement<mixed>
     */
    public function prepare(string $query): TestPDOStatement
    {
        return new TestPDOStatement($query);
    }

    public function getLastInsertId(?string $name = null): string
    {
        return (string) 1;
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function rollBack(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }
}
