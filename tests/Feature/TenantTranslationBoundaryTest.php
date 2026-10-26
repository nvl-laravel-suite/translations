<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Services\ConfiguredTranslationsAuthorization;
use Nvl\Translations\Services\TranslationCatalog;

test('source catalog denies a background actor without an explicit host grant', function (): void {
    app()->bind(TranslationsAuthorization::class, ConfiguredTranslationsAuthorization::class);
    expect(fn () => app(TranslationCatalog::class)->paginate(SystemActor::Background))->toThrow(AuthorizationException::class);
});
