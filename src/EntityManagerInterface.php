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
     * Update or create any entity that is known to the EntityManager if:
     * 1) the entity has changed since capture, update
     * 2) the entity has no ID, create
     * 
     * All entities are re-captured after flushing.
     */
    public function flush(): void;

    public function getHydrator(): EntityHydrator;

    public function getMetadataFactory(): EntityMetadataFactory;
}
