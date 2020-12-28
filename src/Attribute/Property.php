<?php
declare(strict_types=1);

namespace HbLib\ORM\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Property
{
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_DATE = 'date';
    public const TYPE_FLOAT = 'float';
    public const TYPE_INT = 'int';
    public const TYPE_BOOL = 'bool';

    public ?string $dtFormat;
    public bool $dtIsTime;

    /**
     * @phpstan-param self::TYPE_*|null $type
     * @param string|null $type
     */
    public function __construct(
        public ?string $type = null,
        public ?string $name = null,
    ) {
        $this->dtIsTime = $type === self::TYPE_DATETIME;
        $this->dtFormat = match ($type) {
            self::TYPE_DATE => 'Y-m-d',
            self::TYPE_DATETIME => 'Y-m-d H:i:s',
            default => null,
        };
    }
}
