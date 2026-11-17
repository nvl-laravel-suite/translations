<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Adoption\AdoptionCoordinator;
use Nvl\Support\Adoption\AdoptionProgress;
use Nvl\Support\Adoption\AdoptionRegistry;
use Nvl\Support\Adoption\AdoptionStore;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Models\TranslationUsage;
use Nvl\Translations\Tests\CommittedWorkspaceTestCase;
use Nvl\Translations\Tests\Fixtures\TranslationLockWorkers;

uses(CommittedWorkspaceTestCase::class);

beforeEach(function (): void {
    $this->redis = TranslationLockWorkers::configure();
    if ($this->redis === null) {
        $this->markTestSkipped('Requires the isolated committed PostgreSQL package database, phpredis and a dedicated Redis test port.');
    }
    $this->artisan('migrate', ['--path' => dirname((string) (new ReflectionClass(AdoptionStore::class))->getFileName(), 3).'/database/migrations', '--realpath' => true])->assertSuccessful();
    $schema = DB::getSchemaBuilder();
    $schema->create('legacy_lock_entries', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('scope_type', 32);
        $table->string('scope_name', 120);
        $table->string('locale', 12);
        $table->string('format', 16);
        $table->string('group')->nullable();
        $table->string('key');
        $table->text('value')->nullable();
        $table->string('source_hash', 64)->nullable();
        $table->boolean('is_missing')->default(false);
        $table->timestampTz('last_imported_at')->nullable();
        $table->timestampsTz();
    });
    $schema->create('legacy_lock_usages', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('scope_type', 32)->nullable();
        $table->string('scope_name', 120)->nullable();
        $table->string('format', 16);
        $table->string('full_key');
        $table->string('file_path');
        $table->unsignedInteger('line');
        $table->timestampTz('last_seen_at');
        $table->timestampsTz();
    });
    DB::table('legacy_lock_entries')->insert(['id' => '00000000-0000-4000-8000-000000000001', 'scope_type' => 'app', 'scope_name' => 'app', 'locale' => 'en', 'format' => 'json', 'key' => 'Save', 'value' => 'Save']);
    DB::table('legacy_lock_usages')->insert(['id' => '00000000-0000-4000-8000-000000000011', 'scope_type' => 'app', 'scope_name' => 'app', 'format' => 'json', 'full_key' => 'Save', 'file_path' => 'app/main.php', 'line' => 1, 'last_seen_at' => '2026-02-05 10:30:00+00']);
});

afterEach(function (): void {
    if ($this->redis !== null) {
        DB::getSchemaBuilder()->dropIfExists('legacy_lock_usages');
        DB::getSchemaBuilder()->dropIfExists('legacy_lock_entries');
    }
});

/**
 * Hold the workspace lock while a worker runs one adoption phase with a lock wait; once the worker is retrying the
 * workspace lock, return whether it holds the run row, and the worker's outcome after the lock is released.
 *
 * @param  array<string, mixed>  $operation
 * @return array{run_locked: bool, outcome: array<string, mixed>}
 */
function adoptionPhaseWhileWorkspaceLocked(array $redis, array $operation): array
{
    $directory = TranslationLockWorkers::directory();
    $workspace = Cache::store('translations_race')->lock('nvl:translations:workspace', 60);
    expect($workspace->get())->toBeTrue();
    $worker = null;
    try {
        $worker = TranslationLockWorkers::start($directory, $redis, [...$operation, 'wait_seconds' => 30]);
        TranslationLockWorkers::awaitWorkspaceLockWait($worker, $directory);
        try {
            DB::transaction(static fn () => DB::select('select id from '.AdoptionStore::Runs.' for update nowait'));
            $runLocked = false;
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '55P03') {
                throw $exception;
            }
            $runLocked = true;
        }
        expect($worker->isRunning())->toBeTrue('The worker stopped waiting for the workspace lock: '.$worker->getErrorOutput().$worker->getOutput());
        $workspace->release();

        return ['run_locked' => $runLocked, 'outcome' => TranslationLockWorkers::outcome($worker)];
    } finally {
        $workspace->release();
        $worker?->stop();
        TranslationLockWorkers::removeDirectory($directory);
    }
}

it('uses workspace then run locking for apply verify and activate', function (): void {
    $adoption = ['entries_table' => 'legacy_lock_entries', 'usages_table' => 'legacy_lock_usages'];
    $fingerprint = (new AdoptionCoordinator(app(AdoptionRegistry::class)->make('translations', SystemActor::Background, $adoption)))->plan()->fingerprint;

    foreach (['apply' => AdoptionProgress::Applied, 'verify' => 'passed', 'activate' => AdoptionProgress::Active] as $phase => $result) {
        $observed = adoptionPhaseWhileWorkspaceLocked($this->redis, ['operation' => $phase, 'adoption' => $adoption, 'fingerprint' => $fingerprint]);

        expect($observed['run_locked'])->toBeFalse("The {$phase} worker held the adoption run row while waiting for the workspace lock.")
            ->and($observed['outcome'])->toBe(['status' => 'completed', 'result' => $result]);
    }

    expect(TranslationEntry::query()->count())->toBe(1)
        ->and(TranslationUsage::query()->count())->toBe(1)
        ->and(DB::table(AdoptionStore::Runs)->value('status'))->toBe(AdoptionProgress::Active);
});
