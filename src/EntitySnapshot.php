<?php
declare(strict_types=1);

namespace HbLib\ORM;

class EntitySnapshot
{
    /**
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        private ?int $id,
        private array $data,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @phpstan-return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param string $key
     * @param mixed $value
     */
    public function setDataValue(string $key, $value): void
    {
        $this->data[$key] = $value;
    }
}
