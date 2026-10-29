<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
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
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Exceptions\InvalidTranslationInputException;
use Nvl\Translations\Exceptions\StaleTranslationWorkspaceException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Models\TranslationOverride;
use Nvl\Translations\Services\SourceTranslationWorkspace;
use Nvl\Translations\Services\TranslationOverrideModels;
use Nvl\Translations\Tests\Fixtures\ConfiguredOverride;
use Nvl\Translations\Tests\OwnerScopeTestCase;

uses(OwnerScopeTestCase::class);

it('authorizes every override operation using canonical persisted actor privileges', function (string $operation): void {
    Storage::fake('local');
    app()->instance(TranslationsAuthorization::class, new class implements TranslationsAuthorization
    {
        public function authorize(TranslationsAbility $ability, Authenticatable|SystemActor $actor, ?TranslationEntry $entry = null): void
        {
            if (! $actor instanceof Actor || $actor->name !== 'allowed') {
                throw new AuthorizationException;
            }
        }
    });
    $actor = Actor::findOrFail(1);
    app(OwnershipRunner::class)->run(Company::findOrFail(7), $actor, function () use ($actor, $operation): void {
        $actor->forceFill(['name' => 'allowed']);
        $repository = app(TranslationOverrideRepository::class);
        $execute = fn () => match ($operation) {
            'read' => $repository->get($actor, 'legacy.copy', 'en'),
            'create' => $repository->set($actor, 'headline', 'en', 'Allowed', 0),
            'delete' => $repository->forget($actor, 'legacy.copy', 'en', 9),
            'export' => $repository->export($actor, 'local'),
        };
        expect($execute)->toThrow(AuthorizationException::class);
        DB::table('ownership_test_actors')->where('id', $actor->id)->update(['name' => 'allowed']);
        $execute();
        DB::table('ownership_test_actors')->where('id', $actor->id)->update(['name' => 'revoked']);
        expect($execute)->toThrow(AuthorizationException::class);
    });
})->with(['read', 'create', 'delete', 'export']);

it('rejects invalid UTF-8 override values without changing stored revisions', function (): void {
    app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), function (): void {
        $repository = app(TranslationOverrideRepository::class);
        expect(fn () => $repository->set(SystemActor::Background, 'headline', 'en', "\xB1", 0))->toThrow(InvalidTranslationInputException::class)
            ->and(fn () => $repository->set(SystemActor::Background, 'legacy.copy', 'en', "\xB1", 9))->toThrow(InvalidTranslationInputException::class)
            ->and($repository->get(SystemActor::Background, 'legacy.copy', 'en'))->toBe('preserved')
            ->and(ConfiguredOverride::query()->count())->toBe(1);
    });
});

it('preserves configured override mutation hooks and revision checks inside the caller transaction', function (): void {
    app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), function (): void {
        ConfiguredOverride::updating(static function (TranslationOverride $model): void {
            $model->value = strtoupper($model->value);
        });
        ConfiguredOverride::deleting(static function (): void {
            throw new RuntimeException('Consumer deletion rejected.');
        });
        $repository = app(TranslationOverrideRepository::class);
        $repository->set(SystemActor::Background, 'legacy.copy', 'en', 'updated', 9);
        expect($repository->get(SystemActor::Background, 'legacy.copy', 'en'))->toBe('UPDATED')
            ->and(fn () => $repository->set(SystemActor::Background, 'legacy.copy', 'en', 'stale', 9))->toThrow(StaleTranslationWorkspaceException::class)
            ->and(fn () => $repository->forget(SystemActor::Background, 'legacy.copy', 'en', 9))->toThrow(StaleTranslationWorkspaceException::class)
            ->and(fn () => $repository->forget(SystemActor::Background, 'legacy.copy', 'en', 10))->toThrow(RuntimeException::class, 'Consumer deletion rejected.');
        DB::beginTransaction();
        try {
            $repository->set(SystemActor::Background, 'legacy.copy', 'en', 'temporary', 10);
            expect($repository->get(SystemActor::Background, 'legacy.copy', 'en'))->toBe('TEMPORARY');
        } finally {
            DB::rollBack();
        }
        expect($repository->get(SystemActor::Background, 'legacy.copy', 'en'))->toBe('UPDATED')
            ->and(ConfiguredOverride::findOrFail(OwnerScopeTestCase::Override)->revision)->toBe(10);
    });
});

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
