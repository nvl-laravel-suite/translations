<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Exceptions\OwnershipException;
use Nvl\Translations\Contracts\ExportTranslationOverridesContract;
use Nvl\Translations\Contracts\TenantTranslationRepository;
use Nvl\Translations\Contracts\TranslationOverrideRepository;
use Nvl\Translations\Services\SourceTranslationWorkspace;
use Nvl\Translations\Tests\Fixtures\ConfiguredOverride;
use Nvl\Translations\Tests\OwnerScopeTestCase;

uses(OwnerScopeTestCase::class);

it('isolates equal override keys and uses the configured class and private owner export', function (): void {
    Storage::fake('local');
    $runner = app(OwnershipRunner::class);
    foreach ([7 => 'seven', 8 => 'eight'] as $id => $value) {
        $runner->run(Company::findOrFail($id), Actor::findOrFail(1), function () use ($value): void {
            $repository = app(TranslationOverrideRepository::class);
            expect($repository->get('headline', 'en'))->toBeNull();
            $repository->set('headline', 'en', $value, 0);
            expect($repository->get('headline', 'en'))->toBe(strtoupper($value));
        });
    }
    $runner->run(Company::findOrFail(7), Actor::findOrFail(1), function (): void {
        $export = app(ExportTranslationOverridesContract::class)->execute('local');
        $payload = Storage::disk('local')->get($export->path);
        expect($payload)->toContain('SEVEN')->not->toContain('EIGHT')
            ->and($export->owner->key)->toBe('7')->and(Storage::disk('local')->getVisibility($export->path))->toBe('private')
            ->and(ConfiguredOverride::query()->where('key', 'headline')->sole())->toBeInstanceOf(ConfiguredOverride::class);
        expect(fn () => app(SourceTranslationWorkspace::class)->authorize())->toThrow(OwnershipException::class);
        expect(fn () => app(TenantTranslationRepository::class)->get('legacy.copy', 'en'))->toThrow(OwnershipException::class);
    });
});

it('preserves historical identity and revision while retaining the original table unchanged', function (): void {
    $report = app(OwnershipAdoptionCoordinator::class)->verify($this->adoptionPlan);
    expect($report->passed())->toBeTrue()->and($report->resources['translations.overrides']['mapped'])->toBe(1);
    app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), function (): void {
        $row = ConfiguredOverride::findOrFail(OwnerScopeTestCase::Override);
        expect($row->value)->toBe('preserved')->and($row->revision)->toBe(9);
    });
    app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::findOrFail(1), function (): void {
        expect(ConfiguredOverride::find(OwnerScopeTestCase::Override))->toBeNull();
    });
    expect(DB::table('nvl_translations_tenant_overrides')->where('id', OwnerScopeTestCase::Override)->value('revision'))->toBe(9);
});
