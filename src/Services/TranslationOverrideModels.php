<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Support\Models\ConfiguredModelResolver;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Translations\Models\TranslationOverride;

/** Freezes the advertised override replacement before compiling resource identities. @internal */
final class TranslationOverrideModels
{
    /** @var class-string<TranslationOverride>|null */
    private ?string $model = null;

    private bool $validated = false;

    /** Retain deployment configuration and stable model identities. */
    public function __construct(private readonly Repository $configuration, private readonly OwnerRegistry $identities) {}

    /** @return class-string<TranslationOverride> */
    public function prepare(): string
    {
        if ($this->model !== null) {
            return $this->model;
        }
        $configured = $this->configuration->get('nvl-translations.models.override', TranslationOverride::class);
        if (! is_string($configured)) {
            throw new OwnershipConfigurationInvalid('Translation override configuration requires a concrete model class.');
        }
        $class = (new ConfiguredModelResolver)->resolve($configured, TranslationOverride::class);
        if (! in_array($class::ownershipDefinition()->strategy, ['captured', 'column', 'pivot'], true)) {
            throw new OwnershipConfigurationInvalid('Translation overrides require captured, native-column or concrete-pivot ownership.');
        }
        $this->identities->registerReplacement('translations.overrides', TranslationOverride::class, $class);

        return $this->model = $class;
    }

    /** Validate replacement capabilities after Eloquent has installed its dispatcher.
     * @return class-string<TranslationOverride>
     */
    public function modelClass(): string
    {
        $class = $this->prepare();
        if (! $this->validated) {
            (new ConfiguredModelResolver)->assertOwnershipCapabilities(new $class, new TranslationOverride);
            $this->validated = true;
        }

        return $class;
    }
}
