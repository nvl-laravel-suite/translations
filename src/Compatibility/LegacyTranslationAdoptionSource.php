<?php

declare(strict_types=1);

namespace Nvl\Translations\Compatibility;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Translations\Models\TenantTranslationOverride;

/**
 * Names the fixed historical override source; reviewed metadata cannot choose a table.
 *
 * @internal
 */
final readonly class LegacyTranslationAdoptionSource
{
    /** Retain exact compiled native metadata. */
    public function __construct(private OwnershipResourceRegistry $resources) {}

    /** Resolve the historical source only when it exists on the same actual transaction source. */
    public function source(): Model
    {
        $target = new ($this->resources->get('translations.overrides')->model);
        $legacy = new TenantTranslationOverride;
        if (! $legacy->getConnection()->getSchemaBuilder()->hasTable($legacy->getTable())) {
            return $target;
        }
        if ($legacy->getConnection() !== $target->getConnection()) {
            throw new OwnershipConfigurationInvalid('Historical translation adoption must preserve its actual source connection.');
        }

        return $legacy;
    }
}
