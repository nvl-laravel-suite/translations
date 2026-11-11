<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translations\Exceptions\InvalidTranslationInputException;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Models\TranslationUsage;
use Nvl\Translations\Services\TranslationCatalog;
use Nvl\Translations\Services\TranslationExporter;
use Nvl\Translations\Services\TranslationImporter;
use Nvl\Translations\Services\TranslationProcessLock;
use Nvl\Translations\Services\TranslationScanner;
use Nvl\Translations\Services\TranslationsDoctor;

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
