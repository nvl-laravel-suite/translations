<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Models\TranslationEntry;

/**
 * Gate-backed default that denies access until a consumer opts in.
 */
final class ConfiguredTranslationsAuthorization implements TranslationsAuthorization
{
    /**
     * Authorize through the consumer-configured Gate ability.
     */
    public function authorize(
        TranslationsAbility $ability,
        Authenticatable|SystemActor $actor,
        ?TranslationEntry $entry = null,
    ): void {
        $configuredAbility = config('nvl-translations.authorization.ability');

        if (! is_string($configuredAbility) || $configuredAbility === '') {
            throw new AuthorizationException(
                'Translations management requires an authorization binding or configured Gate ability.',
            );
        }

        $arguments = [$ability->value];

        if ($entry instanceof TranslationEntry) {
            $arguments[] = $entry;
        }

        if ($actor instanceof SystemActor) {
            throw new AuthorizationException('System translation operations require an explicit authorization binding.');
        }
        Gate::forUser($actor)->authorize($configuredAbility, $arguments);
    }
}
