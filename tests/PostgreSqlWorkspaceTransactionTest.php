<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translations\Exceptions\TranslationWorkspaceLockedException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Services\TranslationCatalog;
use Nvl\Translations\Services\TranslationImporter;
use Nvl\Translations\Tests\CommittedWorkspaceTestCase;
use Nvl\Translations\Tests\Fixtures\TranslationLockWorkers;

uses(CommittedWorkspaceTestCase::class);

beforeEach(function (): void {
    $this->redis = TranslationLockWorkers::configure();
    if ($this->redis === null) {
        $this->markTestSkipped('Requires the isolated committed PostgreSQL package database, phpredis and a dedicated Redis test port.');
    }
    $this->workspaceRoot = storage_path('framework/testing/nvl-translations-transaction-'.Str::uuid());
    File::ensureDirectoryExists($this->workspaceRoot);
    File::put($this->workspaceRoot.'/en.json', '{"Save":"Save"}');
    config(['nvl-translations.paths.app' => $this->workspaceRoot, 'nvl-translations.discovery.modules' => false, 'nvl-translations.discovery.vendor' => false]);
    app(TranslationImporter::class)->import(SystemActor::Background, ['app'], 'json');
});

afterEach(function (): void {
    if ($this->redis !== null) {
        File::deleteDirectory($this->workspaceRoot);
    }
});

it('holds the workspace lease until the outer transaction commits', function (): void {
    $entry = TranslationEntry::query()->sole();
    $catalog = app(TranslationCatalog::class);
    $directory = TranslationLockWorkers::directory();
    $worker = null;
    DB::beginTransaction();
    try {
        $edited = $catalog->update(SystemActor::Background, $entry->id, ['value' => 'Outer', 'expected_revision' => $entry->revision]);
        $edited = $catalog->update(SystemActor::Background, $entry->id, ['value' => 'Outer again', 'expected_revision' => $edited->revision]);

        $worker = TranslationLockWorkers::start($directory, $this->redis, ['operation' => 'update', 'entry' => $entry->id, 'revision' => $edited->revision]);
        TranslationLockWorkers::await($worker, $directory.'/ready');
        $deadline = microtime(true) + 10;
        while ($worker->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
        $finishedWhileOpen = ! $worker->isRunning();
        DB::commit();

        expect($finishedWhileOpen)->toBeTrue('The worker took the released lease and waited on the open transaction\'s row lock.')
            ->and(TranslationLockWorkers::outcome($worker))->toBe(['status' => 'rejected', 'exception' => TranslationWorkspaceLockedException::class])
            ->and($entry->fresh()?->value)->toBe('Outer again');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $worker?->stop();
        TranslationLockWorkers::removeDirectory($directory);
    }

    $lease = Cache::store('translations_race')->lock('nvl:translations:workspace', 5);
    expect($lease->get())->toBeTrue();
    $lease->release();
});

it('keeps the workspace lease after the nested transaction that took it commits', function (): void {
    $entry = TranslationEntry::query()->sole();
    $catalog = app(TranslationCatalog::class);
    $directory = TranslationLockWorkers::directory();
    $leaseKey = $this->redis['prefix'].'nvl:translations:workspace';
    $worker = null;
    DB::beginTransaction();
    try {
        DB::beginTransaction();
        $edited = $catalog->update(SystemActor::Background, $entry->id, ['value' => 'Nested', 'expected_revision' => $entry->revision]);
        DB::commit();
        $lease = Redis::connection('translations_race')->get($leaseKey);

        // The worker retries the workspace lock while the parent edits the row the committed savepoint still locks.
        $worker = TranslationLockWorkers::start($directory, $this->redis, ['operation' => 'update', 'entry' => $entry->id, 'revision' => $edited->revision + 1, 'wait_seconds' => 30]);
        TranslationLockWorkers::awaitWorkspaceLockWait($worker, $directory);
        $catalog->update(SystemActor::Background, $entry->id, ['value' => 'Parent', 'expected_revision' => $edited->revision]);
        $leaseAfterParentEdit = Redis::connection('translations_race')->get($leaseKey);
        DB::commit();

        expect($lease)->toBeString()
            ->and($leaseAfterParentEdit)->toBe($lease, 'The parent edit released the workspace lease while the committed savepoint\'s row locks were held.')
            ->and(TranslationLockWorkers::outcome($worker))->toBe(['status' => 'completed', 'result' => 'Worker'])
            ->and($entry->fresh()?->value)->toBe('Worker');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack(0);
        }
        $worker?->stop();
        TranslationLockWorkers::removeDirectory($directory);
    }
});

it('releases the workspace lease when the outer transaction rolls back after an edit', function (): void {
    $entry = TranslationEntry::query()->sole();
    $lease = Cache::store('translations_race')->lock('nvl:translations:workspace', 5);

    DB::beginTransaction();
    app(TranslationCatalog::class)->update(SystemActor::Background, $entry->id, ['value' => 'Discarded', 'expected_revision' => $entry->revision]);
    expect($lease->get())->toBeFalse();
    DB::rollBack();

    expect($lease->get())->toBeTrue()
        ->and($entry->fresh()?->value)->toBe('Save');
    $lease->release();
});
