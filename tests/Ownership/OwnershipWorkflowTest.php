<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Exceptions\OwnershipException;
use Nvl\Translations\Contracts\TranslationOverrideRepository;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Models\TranslationOverride;
use Nvl\Translations\Services\SourceTranslationWorkspace;
use Nvl\Translations\Services\TranslationOverrideModels;
use Nvl\Translations\Tests\Fixtures\ConfiguredOverride;
use Nvl\Translations\Tests\OwnerScopeTestCase;

uses(OwnerScopeTestCase::class);

it('keeps override factories on the frozen configured model after a config change', function (): void {
    app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), function (): void {
        $selected = app(TranslationOverrideModels::class)->modelClass();
        expect(TranslationOverride::factory()->make())->toBeInstanceOf(ConfiguredOverride::class);
        config(['nvl-translations.models.override' => TranslationOverride::class]);
        expect(TranslationOverride::factory()->make())->toBeInstanceOf(ConfiguredOverride::class)
            ->and(app(TranslationOverrideModels::class)->modelClass())->toBe($selected);
        $row = app(OwnershipWriter::class)->create($selected, fn (array $ownership) => TranslationOverride::factory()->state([...$ownership, 'value' => 'factory'])->create());
        expect($row)->toBeInstanceOf(ConfiguredOverride::class)
            ->and($row->fresh()->value)->toBe('FACTORY');
    });
});

it('isolates equal override keys and uses the configured class and private owner export', function (): void {
    Storage::fake('local');
    $runner = app(OwnershipRunner::class);
    foreach ([7 => 'seven', 8 => 'eight'] as $id => $value) {
        $runner->run(Company::findOrFail($id), Actor::findOrFail(1), function () use ($value): void {
            $repository = app(TranslationOverrideRepository::class);
            expect($repository->get(SystemActor::Background, 'headline', 'en'))->toBeNull();
            $repository->set(SystemActor::Background, 'headline', 'en', $value, 0);
            expect($repository->get(SystemActor::Background, 'headline', 'en'))->toBe(strtoupper($value));
        });
    }
    $runner->run(Company::findOrFail(7), Actor::findOrFail(1), function (): void {
        $export = app(TranslationOverrideRepository::class)->export(SystemActor::Background, 'local');
        $payload = Storage::disk('local')->get($export['path']);
        expect($payload)->toContain('SEVEN')->not->toContain('EIGHT')
            ->and($export['owner']['key'])->toBe('7')->and(Storage::disk('local')->getVisibility($export['path']))->toBe('private')
            ->and(ConfiguredOverride::query()->where('key', 'headline')->sole())->toBeInstanceOf(ConfiguredOverride::class);
        expect(fn () => app(SourceTranslationWorkspace::class)->authorize(SystemActor::Background, TranslationsAbility::ListEntries))->toThrow(OwnershipException::class);
    });
});

it('preserves historical identity and revision through explicit adoption of populated native storage', function (): void {
    $report = app(OwnershipAdoptionCoordinator::class)->verify($this->adoptionPlan);
    expect($report->passed())->toBeTrue()->and($report->resources['translations.overrides']['mapped'])->toBe(1);
    app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), function (): void {
        $row = ConfiguredOverride::findOrFail(OwnerScopeTestCase::Override);
        expect($row->value)->toBe('preserved')->and($row->revision)->toBe(9);
    });
    app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::findOrFail(1), function (): void {
        expect(ConfiguredOverride::find(OwnerScopeTestCase::Override))->toBeNull();
    });
    expect(DB::table('nvl_translations_overrides')->where('id', OwnerScopeTestCase::Override)->value('revision'))->toBe(9);
});
