<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translations\Contracts\TranslationOverrideRepository;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Providers\TranslationsServiceProvider;
use Nvl\Translations\Services\ConfiguredTranslationsAuthorization;
use Nvl\Translations\Services\TranslationImporter;
use Nvl\Translations\Services\TranslationScanner;

test('consumer configuration wins while omitted nested package defaults remain available', function (): void {
    config()->set('nvl-translations', [
        'import' => [
            'conflict_strategy' => 'prefer_database',
        ],
    ]);

    (new TranslationsServiceProvider(app()))->register();

    expect(config('nvl-translations.import.conflict_strategy'))->toBe('prefer_database')
        ->and(config('nvl-translations.paths.app'))->toBe(lang_path())
        ->and(config('nvl-translations.export_targets.source'))->toBe([])
        ->and(config('nvl-translations.scan_allowlist'))->toBe(['errors.*']);
});

test('the published configuration declares the documented catalog connection', function (): void {
    $defaults = require __DIR__.'/../../config/nvl-translations.php';
    $published = require __DIR__.'/../../resources/config/nvl-translations.php';

    expect(array_key_exists('connection', $defaults))->toBeTrue()
        ->and($defaults['connection'])->toBeNull()
        ->and(array_key_exists('connection', $published))->toBeTrue()
        ->and(config()->has('nvl-translations.connection'))->toBeTrue();
});

test('consumer service bindings survive package provider registration', function (): void {
    $contracts = [
        TranslationOverrideRepository::class,
        TranslationsAuthorization::class,
        TranslationImporter::class,
        TranslationScanner::class,
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
        ->and(fn () => app(ConfiguredTranslationsAuthorization::class)->authorize(
            TranslationsAbility::ListEntries, SystemActor::Background,
        ))
        ->toThrow(AuthorizationException::class);
});
