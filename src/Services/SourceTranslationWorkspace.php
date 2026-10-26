<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipContextResolver;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Models\TranslationEntry;

/** Keeps source-code catalog tooling outside owner execution and owner copy persistence. */
final readonly class SourceTranslationWorkspace
{
    /** Resolve current native state at each operation and admit the unowned source workspace. */
    public function __construct(private OwnershipContextResolver $ownership, private TranslationsAuthorization $authorization) {}

    /** Deny before catalog or filesystem access from any admitted owner or public site. */
    public function authorize(Authenticatable|SystemActor $actor, TranslationsAbility $ability, ?TranslationEntry $entry = null): void
    {
        if ($actor instanceof Authenticatable && (! $actor instanceof Model || ! $actor->exists || $actor->getKey() === null || $actor->getRawOriginal($actor->getKeyName()) !== $actor->getKey() || ! $actor->newQuery()->whereKey($actor->getKey())->exists())) {
            throw new AuthorizationException('Translation actors must be persisted models.');
        }
        $this->authorization->authorize($ability, $actor, $entry);
        $snapshot = $this->ownership->current()->snapshot();
        if ($snapshot->mode === OwnershipContextMode::Disabled) {
            return;
        }
        if ($snapshot->publicSite !== null || ! in_array($snapshot->mode, [OwnershipContextMode::Unresolved, OwnershipContextMode::Central], true)) {
            throw new OwnershipViolation('Source translation tooling is unavailable inside owner execution.');
        }
    }
}
