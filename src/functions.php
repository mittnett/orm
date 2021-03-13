<?php declare(strict_types=1);

namespace HbLib\ORM {
    /**
     * @param class-string<IdentifiableEntityInterface> $className
     * @param int $id
     * @return UnloadedItem<IdentifiableEntityInterface>
     */
    function create_unloaded_item(string $className, int $id)
    {
        return new UnloadedItem($id);
    }
}
