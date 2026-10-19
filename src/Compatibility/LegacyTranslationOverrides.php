<?php

declare(strict_types=1);

namespace Nvl\Translations\Compatibility;

use Illuminate\Contracts\Config\Repository;
use Nvl\Support\Models\ConfiguredModelResolver;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;
use Nvl\Translations\Models\TenantTranslationOverride;
use Nvl\Translations\Models\TranslationOverride;

/** Prevents old override APIs from becoming a second authority after native adoption. @internal */
final readonly class LegacyTranslationOverrides
{
    /** Retain canonical declarations and actual-source downgrade detection. */
    public function __construct(private OwnershipResourceRegistry $resources, private Repository $configuration, private ConfiguredModelResolver $models, private PersistedOwnershipStorage $storage) {}

    /** Return the historical model without treating it as a native owner declaration.
     * @return class-string<TenantTranslationOverride>
     */
    public static function modelClass(): string
    {
        return TenantTranslationOverride::class;
    }

    /** Deny native declarations and retained adoption markers before old queries or export effects. */
    public function assertLegacy(): void
    {
        if (($this->resources->all()['translations.overrides'] ?? null)?->ownership->strategy !== 'unscoped') {
            throw new OwnershipViolation('Legacy translation APIs cannot access native owner overrides.');
        }
        $configured = $this->configuration->get('nvl-translations.models.override', TranslationOverride::class);
        if (! is_string($configured)) {
            throw new OwnershipViolation('The canonical override model configuration is invalid.');
        }
        $class = $this->models->resolve($configured, TranslationOverride::class);
        $model = new $class;
        $this->storage->assertUnadopted($model->getConnection(), 'translations.overrides', $model->getTable());
    }
}
