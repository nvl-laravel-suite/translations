<?php

declare(strict_types=1);

namespace Nvl\Translations\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Checks fixture identities against native persistence and ownership admission.
 *
 * @internal
 */
final class FactoryGuard
{
    /** Require a scalar native identifier before lookup or morph serialization. */
    public static function identifier(mixed $key): int|string
    {
        if ((! is_string($key) && ! is_int($key)) || $key === '') {
            throw new InvalidArgumentException('Factory identities require a non-empty native scalar identifier.');
        }

        return $key;
    }

    /** Require a persisted parent on the fixture's effective connection. */
    public static function parent(Model $parent, Model $child): void
    {
        $key = $parent->getKey();
        if (! $parent->exists || (! is_string($key) && ! is_int($key))
            || $key === '' || $parent->getRawOriginal($parent->getKeyName()) !== $key) {
            throw new InvalidArgumentException('Factory parents require an unchanged persisted native identifier.');
        }
        if ($parent->getConnection() !== $child->getConnection()) {
            throw new InvalidArgumentException('Factory parents and children require the same effective connection.');
        }
        $container = Container::getInstance();
        $registry = $container->make(OwnershipResourceRegistry::class);
        $resource = $registry->forModel($parent);
        $container->make(OwnershipBoundary::class)->assertRecord($parent, $resource->key);
    }

    private function __construct() {}
}
