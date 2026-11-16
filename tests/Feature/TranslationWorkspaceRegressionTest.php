<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translations\Definitions\Tables\TranslationsTables;
use Nvl\Translations\Enums\TranslationSyncStatus;
use Nvl\Translations\Exceptions\InvalidTranslationInputException;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Models\TranslationUsage;
use Nvl\Translations\Services\TranslationCatalog;
use Nvl\Translations\Services\TranslationCatalogAdopter;
use Nvl\Translations\Services\TranslationExporter;
use Nvl\Translations\Services\TranslationImporter;
use Nvl\Translations\Services\TranslationProcessLock;
use Nvl\Translations\Services\TranslationScanner;
use Nvl\Translations\Services\TranslationsDoctor;
use Nvl\Translations\Support\TranslationSelection;
use Nvl\Translations\Support\TranslationValueHash;

beforeEach(function (): void {
    $this->regressionRoot = storage_path('framework/testing/nvl-translations-regression-'.Str::uuid());
    $this->regressionSource = $this->regressionRoot.'/source';
    $this->regressionTarget = $this->regressionRoot.'/target';

    File::ensureDirectoryExists($this->regressionSource);

    config([
        'nvl-translations.paths.app' => $this->regressionSource,
        'nvl-translations.discovery.modules' => false,
        'nvl-translations.discovery.vendor' => false,
        'nvl-translations.custom_scopes' => [],
        'nvl-translations.export_targets' => [
            'source' => [],
            'generated' => ['app' => $this->regressionTarget],
        ],
        'nvl-translations.import.conflict_strategy' => 'prefer_database',
        'nvl-translations.import.fail_on_error' => true,
        'nvl-translations.backup.directory' => $this->regressionRoot.'/backups',
        'nvl-translations.scan.paths' => [$this->regressionRoot.'/code'],
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->regressionRoot);
    DB::getSchemaBuilder()->dropIfExists('legacy_translation_usages');
    DB::getSchemaBuilder()->dropIfExists('legacy_translation_entries');
});

/** @return array{entries_table: string, usages_table: string} */
function createLegacyTranslationCatalog(): array
{
    DB::getSchemaBuilder()->create('legacy_translation_entries', static function (Blueprint $table): void {
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
    DB::getSchemaBuilder()->create('legacy_translation_usages', static function (Blueprint $table): void {
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

    return ['entries_table' => 'legacy_translation_entries', 'usages_table' => 'legacy_translation_usages'];
}

test('adopts populated legacy translations and resumes a staged copy with the reviewed fingerprint', function (): void {
    $source = createLegacyTranslationCatalog();
    $jsonId = (string) Str::uuid();
    $mailId = (string) Str::uuid();
    $usageId = (string) Str::uuid();
    $timestamp = '2026-02-05 10:30:00+02:00';
    DB::table($source['entries_table'])->insert([
        ['id' => $jsonId, 'scope_type' => 'app', 'scope_name' => 'app', 'locale' => 'en', 'format' => 'json', 'group' => null, 'key' => 'Save', 'value' => 'Save', 'source_hash' => hash('sha256', 'Save'), 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ['id' => $mailId, 'scope_type' => 'mail', 'scope_name' => 'mail', 'locale' => 'en', 'format' => 'php', 'group' => 'mail/orders', 'key' => 'title', 'value' => 'Edited', 'source_hash' => hash('sha256', 'Original'), 'created_at' => $timestamp, 'updated_at' => $timestamp],
    ]);
    DB::table($source['usages_table'])->insert([
        'id' => $usageId, 'scope_type' => 'app', 'scope_name' => 'app', 'format' => 'json', 'full_key' => 'Save',
        'file_path' => 'app/main.php', 'line' => 12, 'last_seen_at' => $timestamp, 'created_at' => $timestamp,
    ]);

    $actor = SystemActor::Background;
    $adopter = app(TranslationCatalogAdopter::class);
    expect(fn () => $adopter->plan($actor, $source))->toThrow(InvalidTranslationInputException::class, $mailId);
    $source['source_values'] = [$mailId => 'Original'];
    $plan = $adopter->plan($actor, $source);
    expect($plan)->toMatchArray(['entries' => 2, 'usages' => 1, 'staged_entries' => 0, 'staged_usages' => 0, 'conflicts' => []]);
    expect(TranslationEntry::count())->toBe(0)->and(TranslationUsage::count())->toBe(0);
    expect(fn () => $adopter->verify($actor, $plan['fingerprint'], $source))->toThrow(TranslationsException::class, 'not fully staged');

    $interrupt = true;
    $usageTable = TranslationsTables::get(TranslationsTables::Usages);
    DB::connection()->beforeExecuting(static function (string $sql) use (&$interrupt, $usageTable): void {
        if ($interrupt && str_starts_with($sql, 'insert into "'.$usageTable.'"')) {
            $interrupt = false;

            throw new RuntimeException('Interrupted staging');
        }
    });
    expect(fn () => $adopter->apply($actor, $plan['fingerprint'], $source))->toThrow(RuntimeException::class, 'Interrupted staging');
    expect(TranslationEntry::count())->toBe(2)->and(TranslationUsage::count())->toBe(0);

    expect($adopter->apply($actor, $plan['fingerprint'], $source))->toMatchArray(['staged_entries' => 2, 'staged_usages' => 1, 'conflicts' => []]);
    expect($adopter->verify($actor, $plan['fingerprint'], $source))->toBe($adopter->apply($actor, $plan['fingerprint'], $source));
    DB::transaction(function () use ($adopter, $actor, $plan, $source): void {
        expect(fn () => $adopter->verify($actor, $plan['fingerprint'], $source))->toThrow(TranslationsException::class, 'outside existing transactions');
    });
    expect(TranslationEntry::findOrFail($jsonId))->group->toBe('*')->revision->toBe(1)
        ->and(TranslationEntry::findOrFail($mailId))->scope_type->toBe('app')->scope_name->toBe('app')
        ->source_hash->toBe(TranslationValueHash::make('Original'))->sync_status->toBe(TranslationSyncStatus::Edited)
        ->and(TranslationEntry::findOrFail($mailId)->created_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-02-05 08:30:00')
        ->and(TranslationUsage::findOrFail($usageId))->file_path->toBe('app/main.php')->line->toBe(12)
        ->and(DB::table($source['entries_table'])->where('id', $jsonId)->sole()->group)->toBeNull();
});

test('reports conflicting normalized legacy identities and rejects incompatible schemas', function (): void {
    $source = createLegacyTranslationCatalog();
    $firstId = (string) Str::uuid();
    $secondId = (string) Str::uuid();
    $record = ['locale' => 'en', 'format' => 'php', 'group' => 'mail', 'key' => 'welcome'];
    DB::table($source['entries_table'])->insert([
        $record + ['id' => $firstId, 'scope_type' => 'app', 'scope_name' => 'app', 'value' => 'App'],
        $record + ['id' => $secondId, 'scope_type' => 'mail', 'scope_name' => 'mail', 'value' => 'Mail'],
    ]);

    $actor = SystemActor::Background;
    $adopter = app(TranslationCatalogAdopter::class);
    $plan = $adopter->plan($actor, $source);
    expect($plan['conflicts'])->toHaveCount(1)
        ->and($plan['conflicts'][0])->toContain($firstId, $secondId);
    expect(fn () => $adopter->apply($actor, $plan['fingerprint'], $source))->toThrow(TranslationsException::class, 'conflicts');
    expect(TranslationEntry::count())->toBe(0);

    DB::table($source['entries_table'])->where('id', $secondId)->delete();
    expect(fn () => $adopter->apply($actor, $plan['fingerprint'], $source))->toThrow(TranslationsException::class, 'changed');

    DB::getSchemaBuilder()->table($source['usages_table'], static fn (Blueprint $table) => $table->dropColumn('last_seen_at'));
    expect(fn () => $adopter->plan($actor, $source))->toThrow(InvalidTranslationInputException::class, 'last_seen_at');
});

test('imports read the current PHP catalog instead of a compiled copy cached before an export', function (): void {
    $path = $this->regressionSource.'/en/messages.php';
    File::ensureDirectoryExists(dirname($path));
    File::put($path, "<?php\n\nreturn ['title' => 'Original'];\n");
    touch($path, time() - 60);

    app(TranslationImporter::class)->import(SystemActor::Background, ['app'], 'php');
    $entry = TranslationEntry::query()->sole();
    app(TranslationCatalog::class)->update(SystemActor::Background, $entry, [
        'value' => 'Edited',
        'expected_revision' => $entry->revision,
    ]);
    app(TranslationExporter::class)->export(SystemActor::Background, ['app'], null, 'php', 'source');

    $result = app(TranslationImporter::class)->import(SystemActor::Background, ['app'], 'php');

    expect($result['updated'])->toBe(0)
        ->and($entry->fresh()->value)->toBe('Edited');
})->skip(function (): bool {
    $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

    return ! is_array($status) || ($status['opcache_enabled'] ?? false) !== true;
}, 'Requires OPcache for the CLI (run with -d opcache.enable_cli=1).');

test('a malformed entry identifier is not found on every database driver', function (): void {
    expect(fn () => app(TranslationCatalog::class)->update(SystemActor::Background, 'not-a-uuid', [
        'value' => 'Changed',
        'expected_revision' => 1,
    ]))->toThrow(ModelNotFoundException::class);
});

test('key matching ignores letter case on every database driver', function (): void {
    File::put($this->regressionSource.'/en.json', '{"Save Changes":"Save changes","Cancel":"Cancel"}');
    app(TranslationImporter::class)->import(SystemActor::Background, ['app'], 'json');

    $page = app(TranslationCatalog::class)->paginate(SystemActor::Background, ['key_contains' => 'save ch']);

    expect($page->total())->toBe(1)
        ->and($page->first()?->key)->toBe('Save Changes');
});

test('catalog text searches fold Cyrillic and match literal wildcard characters', function (): void {
    File::put($this->regressionSource.'/en.json', '{"ПРИВЕТ !%_":"Первый !%_","Привет !xy":"Первый !xy"}');
    app(TranslationImporter::class)->import(SystemActor::Background, ['app'], 'json');

    $catalog = app(TranslationCatalog::class);
    expect($catalog->paginate(SystemActor::Background, ['key_contains' => 'привет !%_'])->items())->toHaveCount(1)
        ->and($catalog->paginate(SystemActor::Background, ['search' => 'пЕрВыЙ !%_'])->items())->toHaveCount(1)
        ->and($catalog->paginate(SystemActor::Background, ['search' => 'пЕрВыЙ !%_'])->first()?->key)->toBe('ПРИВЕТ !%_');
});

test('group selection limits preflight, exports, missing rows and stale-file pruning to exact group paths', function (): void {
    foreach (['mail', 'mail/orders', 'mail/old', 'mailer'] as $group) {
        $path = $this->regressionSource.'/en/'.$group.'.php';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "<?php return ['text' => 'Original'];");
    }
    File::put($this->regressionSource.'/en.json', '{"Save":"Save"}');
    File::ensureDirectoryExists($this->regressionSource.'/bg');
    File::put($this->regressionSource.'/bg/mailer.php', "<?php return ['text' => 'Original'];");
    $actor = SystemActor::Background;
    app(TranslationImporter::class)->import($actor, ['app']);

    File::delete($this->regressionSource.'/en/mail/old.php');
    File::delete($this->regressionSource.'/en.json');
    File::put($this->regressionSource.'/en/mailer.php', '<?php throw new RuntimeException("Unselected catalog");');

    foreach (['mail/stale', 'mailer'] as $group) {
        $path = $this->regressionTarget.'/en/'.$group.'.php';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '<?php return [];');
    }

    $selection = new TranslationSelection(['app'], 'php', ['mail']);
    $preview = app(TranslationExporter::class)->export($actor, $selection, target: 'generated', prune: true, dryRun: true);
    expect($preview)->toMatchArray(['locales' => 1, 'files' => 2, 'deleted' => 1])
        ->and(TranslationEntry::query()->where('is_missing', true)->count())->toBe(0)
        ->and(File::exists($this->regressionTarget.'/en/mail/stale.php'))->toBeTrue();

    expect(Artisan::call('nvl:translations:export', [
        '--scope' => ['app'], '--group-prefix' => ['mail'], '--format' => 'php',
        '--target' => 'generated', '--prune' => true, '--force' => true,
    ]))->toBe(0);
    expect(File::exists($this->regressionTarget.'/en/mail.php'))->toBeTrue()
        ->and(File::exists($this->regressionTarget.'/en/mail/orders.php'))->toBeTrue()
        ->and(File::get($this->regressionTarget.'/en/mailer.php'))->toBe('<?php return [];')
        ->and(File::exists($this->regressionTarget.'/en/mail/stale.php'))->toBeFalse()
        ->and(TranslationEntry::query()->where('is_missing', true)->pluck('group')->all())->toBe(['mail/old']);

    expect(fn () => new TranslationSelection(['app'], 'php', ['mail/../vendor']))->toThrow(InvalidTranslationInputException::class);
});

test('doctor inspects the lock store that workspace operations actually use', function (): void {
    config([
        'nvl-translations.locks.store' => null,
        'nvl-core.locks.store' => 'without-locks',
        'cache.stores.without-locks' => ['driver' => 'apc'],
    ]);

    $check = collect(app(TranslationsDoctor::class)->inspect())->firstWhere('key', 'lock.workspace');

    expect(fn () => app(TranslationProcessLock::class)->execute('sync', static fn (): null => null))
        ->toThrow(TranslationsException::class, 'does not support atomic locks')
        ->and($check['passed'])->toBeFalse();
});

test('scanner records keys with empty dot segments as JSON usage', function (): void {
    $codeRoot = $this->regressionRoot.'/code';
    File::ensureDirectoryExists($codeRoot);
    File::put($codeRoot.'/Example.php', "<?php\n\n__('Loading...');\n__('Saved.');\n__('messages.save');\n");
    File::put($this->regressionSource.'/en.json', '{"Loading...":"Loading...","Saved.":"Saved."}');
    app(TranslationImporter::class)->import(SystemActor::Background, ['app'], 'json');

    app(TranslationScanner::class)->scan(SystemActor::Background);
    $formats = TranslationUsage::query()->pluck('format', 'full_key')->all();
    ksort($formats);

    expect($formats)->toBe(['Loading...' => 'json', 'Saved.' => 'json', 'messages.save' => 'php'])
        ->and(app(TranslationCatalog::class)->unused(SystemActor::Background, ['app'])['total'])->toBe(0);
});

test('unchanged entries keep their revision and modification time across synchronization and source export', function (): void {
    File::put($this->regressionSource.'/en.json', '{"Cancel":"Cancel","Save":"Save"}');
    app(TranslationImporter::class)->import(SystemActor::Background, ['app'], 'json');
    TranslationEntry::query()->toBase()->update(['updated_at' => '2026-01-01 00:00:00']);
    $cancel = TranslationEntry::query()->where('key', 'Cancel')->sole();
    $save = TranslationEntry::query()->where('key', 'Save')->sole();

    $repeated = app(TranslationImporter::class)->import(SystemActor::Background, ['app'], 'json');

    expect($repeated['updated'])->toBe(0)
        ->and($cancel->fresh()->revision)->toBe($cancel->revision)
        ->and($cancel->fresh()->updated_at->equalTo($cancel->updated_at))->toBeTrue();

    $edited = app(TranslationCatalog::class)->update(SystemActor::Background, $save, [
        'value' => 'Save changes',
        'expected_revision' => $save->revision,
    ]);
    app(TranslationExporter::class)->export(SystemActor::Background, ['app'], null, 'json', 'source');
    $exportedCancel = $cancel->fresh();
    $exportedSave = $save->fresh();

    expect(json_decode((string) File::get($this->regressionSource.'/en.json'), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['Cancel' => 'Cancel', 'Save' => 'Save changes'])
        ->and($exportedCancel->revision)->toBe($cancel->revision)
        ->and($exportedCancel->updated_at->equalTo($cancel->updated_at))->toBeTrue()
        ->and($exportedCancel->last_exported_at)->not->toBeNull()
        ->and($exportedSave->revision)->toBe($edited->revision + 1)
        ->and($exportedSave->sync_status->value)->toBe('synchronized');
});

test('exports neither replace nor back up artifacts whose content is unchanged', function (): void {
    File::put($this->regressionSource.'/en.json', '{"Save":"Save"}');
    app(TranslationExporter::class)->export(SystemActor::Background, ['app'], null, 'json', 'generated');
    $artifact = $this->regressionTarget.'/en.json';
    $inode = fileinode($artifact);

    $repeated = app(TranslationExporter::class)->export(SystemActor::Background, ['app'], null, 'json', 'generated');

    expect($repeated['files'])->toBe(1)
        ->and(fileinode($artifact))->toBe($inode)
        ->and(File::isDirectory($this->regressionRoot.'/backups'))->toBeFalse();

    File::put($this->regressionSource.'/en.json', '{"Save":"Save now"}');
    app(TranslationExporter::class)->export(SystemActor::Background, ['app'], null, 'json', 'generated');

    expect(File::get($artifact))->toContain('Save now')
        ->and(File::allFiles($this->regressionRoot.'/backups'))->toHaveCount(1);
});

test('application exports cannot address the nested vendor directory as a locale', function (string $locale): void {
    $vendorCatalog = $this->regressionSource.'/vendor/package/en/messages.php';
    File::ensureDirectoryExists(dirname($vendorCatalog));
    File::put($vendorCatalog, "<?php\n\nreturn ['title' => 'Vendor'];\n");

    expect(fn () => app(TranslationExporter::class)->export(SystemActor::Background, ['app'], [$locale], 'php', 'source', true))
        ->toThrow(InvalidTranslationInputException::class)
        ->and(File::exists($vendorCatalog))->toBeTrue();
})->with(['vendor', 'Vendor']);

test('default scanner patterns capture keys that contain escaped or mixed quotes', function (): void {
    $codeRoot = $this->regressionRoot.'/code';
    File::ensureDirectoryExists($codeRoot);
    File::put($codeRoot.'/Example.php', <<<'PHP'
<?php

__('Don\'t have an account?');
__("You're logged in!");
trans('Say "hello"');
@lang("A \"quoted\" word")
PHP);
    File::put($codeRoot.'/Example.ts', "t('It\\'s ready')\n");
    $keys = ["Don't have an account?", "You're logged in!", 'Say "hello"', 'A "quoted" word', "It's ready"];
    File::put($this->regressionSource.'/en.json', json_encode(array_combine($keys, $keys), JSON_THROW_ON_ERROR));
    app(TranslationImporter::class)->import(SystemActor::Background, ['app'], 'json');

    app(TranslationScanner::class)->scan(SystemActor::Background);
    $recorded = TranslationUsage::query()->pluck('full_key')->all();
    sort($recorded);
    sort($keys);

    expect($recorded)->toBe($keys)
        ->and(app(TranslationCatalog::class)->unused(SystemActor::Background, ['app'])['total'])->toBe(0);
});

test('the unused command says when no completed scan backs its report', function (): void {
    File::put($this->regressionSource.'/en.json', '{"Save":"Save"}');
    app(TranslationImporter::class)->import(SystemActor::Background, ['app'], 'json');

    $this->artisan('nvl:translations:unused')
        ->expectsOutputToContain('No completed scan exists')
        ->assertSuccessful();
});
