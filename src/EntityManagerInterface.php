<?php
declare(strict_types=1);

namespace HbLib\ORM;

use HbLib\DBAL\DatabaseConnectionInterface;

/**
 * @author Edvin Hultberg <edvin@hultberg.no>
 */
interface EntityManagerInterface
{
    /**
     * @template T
     * @param class-string<T> $className
     * @param int $id
     * @param bool $forUpdateLock
     * @return T|null
     */
    public function find(string $className, int $id, bool $forUpdateLock = false);

    /**
     * @template T
     * @param class-string<T> $className
     * @param int[] $ids
     * @param bool $forUpdateLock
     * @return T[]
     */
    public function findById(string $className, array $ids, bool $forUpdateLock = false): array;

    public function getDatabaseConnection(): DatabaseConnectionInterface;

    public function beginTransaction(): void;

    public function rollBack(): void;

    public function commit(): void;

    /**
     * Snapshot the provided entities to store data for later updating the entities.
     *
     * Any entity intended for update must be snapshot first.
     *
     * The manager must store the entities with a weak reference.
     *
     * @phpstan-template T
     * @param T[] $entities
     */
    public function capture(array $entities): void;

    /**
     * Delete the provided entities.
     *
     * @phpstan-template T
     * @param T[] $entities
     */
    public function delete(array $entities): void;

    /**
     * Flush any entity with UPDATE or INSERT based on:
     * 1) INSERT if the entity is not known or has no ID when snapshotted.
     * 2) UPDATE if the entity is known and has an ID
     *
     * If null is provided for $entities then all previously captured entities are flushed and recaptured.
     *
     * @phpstan-template T
     * @param T[]|null $entities
     */
    public function flush(?array $entities = null): void;

    public function getHydrator(): EntityHydrator;

    public function getMetadataFactory(): EntityMetadataFactory;
}
