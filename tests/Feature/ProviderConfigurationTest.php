<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Nvl\Translations\Contracts\ImportTranslationsContract;
use Nvl\Translations\Contracts\ScanTranslationsContract;
use Nvl\Translations\Contracts\TenantTranslationRepository;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Contracts\UpdateTranslationEntryContract;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Providers\TranslationsServiceProvider;

test('consumer configuration wins while omitted nested package defaults remain available', function (): void {
    config()->set('nvl-translations', [
        'routes' => [
            'prefix' => 'consumer/translations',
        ],
    ]);

    (new TranslationsServiceProvider(app()))->register();

    expect(config('nvl-translations.routes.prefix'))->toBe('consumer/translations')
        ->and(config('nvl-translations.routes.enabled'))->toBeFalse()
        ->and(config('nvl-translations.routes.management_middleware'))->toBe(['auth'])
        ->and(config('nvl-translations.paths.app'))->toBe(lang_path())
        ->and(config('nvl-translations.export_targets.source'))->toBe([])
        ->and(config('nvl-translations.import.conflict_strategy'))->toBe('fail')
        ->and(config('nvl-translations.scan_allowlist'))->toBe(['errors.*']);
});

test('consumer service bindings survive package provider registration', function (): void {
    $contracts = [
        TenantTranslationRepository::class,
        UpdateTranslationEntryContract::class,
        ImportTranslationsContract::class,
        ScanTranslationsContract::class,
    ];
    $consumerBindings = [];

    foreach ($contracts as $contract) {
        $consumerBindings[$contract] = app($contract);
        app()->instance($contract, $consumerBindings[$contract]);
    }

    (new TranslationsServiceProvider(app()))->register();

    foreach ($consumerBindings as $contract => $instance) {
        expect(app($contract))->toBe($instance);
    }
});

test('package validation translations load for supported locales', function (): void {
    expect(trans('nvl-translations::translations/validation.attributes.value', [], 'en'))
        ->toBe('translation value')
        ->and(trans('nvl-translations::translations/validation.attributes.value', [], 'bg'))
        ->toBe('стойност на превода');
});

test('management routes remain disabled by default', function (): void {
    $this->getJson('/nvl/api/v1/translations')->assertNotFound();
    $this->postJson('/nvl/api/v1/translations/import')->assertNotFound();
    $this->postJson('/nvl/api/v1/translations/export')->assertNotFound();
    $this->postJson('/nvl/api/v1/translations/scan')->assertNotFound();
});

test('the package does not infer an application authorization policy', function (): void {
    expect(config('nvl-translations.authorization.ability'))->toBeNull()
        ->and(fn () => app(TranslationsAuthorization::class)->authorize(
            TranslationsAbility::ListEntries,
        ))
        ->toThrow(AuthorizationException::class);
});
