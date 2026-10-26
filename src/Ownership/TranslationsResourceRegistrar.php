<?php

declare(strict_types=1);

namespace Nvl\Translations\Ownership;

use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Models\TranslationScanRun;
use Nvl\Translations\Models\TranslationUsage;
use Nvl\Translations\Services\TranslationOverrideModels;

/**
 * Registers global source catalogs separately from optional native owner copy.
 *
 * @internal
 */
final readonly class TranslationsResourceRegistrar
{
    /** Retain the advertised native replacement registry. */
    public function __construct(private TranslationOverrideModels $models) {}

    /** Compile canonical global and owner-specific model definitions. */
    public function register(OwnershipResourceRegistry $resources, bool $owned): void
    {
        foreach (['translations.catalog' => TranslationEntry::class, 'translations.usages' => TranslationUsage::class, 'translations.scans' => TranslationScanRun::class] as $key => $model) {
            $resources->register(new OwnershipResourceDefinition($key, 'translations', $model, OwnershipDefinition::unscoped(), allowsCentral: true));
        }
        $class = $this->models->modelClass();
        $resources->register(new OwnershipResourceDefinition('translations.overrides', 'translations', $class, $owned ? $class::ownershipDefinition() : OwnershipDefinition::unscoped(), immutableFields: $owned ? ['key', 'locale'] : []));
    }
}
