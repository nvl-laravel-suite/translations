# NVL Translations

Native Laravel 13 translation catalog scanning, editing, import and export. Requires PHP 8.4 and `nvl/core:^6.0`; `nvl/ownership:^6.0` optionally isolates owner copy overrides. Use `nvl/translatable` for localized Eloquent content.
Eloquent actors are reloaded from their class-defined canonical storage before source-workspace policies inspect them. Dirty keys, altered storage, detached actors and unavailable rows reject; caller attributes and loaded relations do not carry policy privileges. These entry points retain their persisted Eloquent-actor requirement; the host policy still decides access. Explicit system actors remain subject to that policy.


## Native API

Inject `Nvl\Translations\Services\TranslationImporter`, `TranslationExporter`, `TranslationScanner`, and `TranslationCatalog`. Every operation takes an explicit persisted `Authenticatable` or `SystemActor` first. Bind `TranslationsAuthorization` to a host policy; the default denies access until a Gate ability is configured, and system actors require an explicit policy binding.

```php
$summary = $importer->import($actor, ['app'], 'both', dryRun: false);
$artifacts = $exporter->export($actor, ['app'], ['en'], 'both', 'generated');
$paths = $artifacts['paths'];
$scan = $scanner->scan($actor);
$page = $catalog->paginate($actor, ['locale' => 'en', 'sort' => ['key']], 25);
$entry = $catalog->update($actor, $entry, ['value' => null, 'expected_revision' => $entry->revision]);
$statistics = $catalog->statistics($actor, ['is_missing' => true]);
$unused = $catalog->unused($actor, ['app'], days: 30);
```

Import and export use configured scope tokens and named destinations, never arbitrary caller paths. Export returns an array summary plus actual artifact paths; a batch can contain multiple PHP and JSON files. Scanning uses configured source roots. Catalog criteria allow `scope_type`, `scope_name`, `locale`, `format`, `group`, `key`, `key_contains`, `search`, `missing_value`, `changed_since_import`, `is_missing`, and `sort`. String fields accept exact strings or lists; state fields require booleans. Sorts are allowlisted and tied by ID.

`TranslationOverrideRepository` provides `get($actor, $key, $locale)`, `set($actor, $key, $locale, $value, $expectedRevision)`, `forget($actor, $key, $locale, $expectedRevision)` and `export($actor, $disk)` inside an admitted native owner context. Exports are private and return owner identity, disk, path, SHA-256 and size. Revision zero creates an override; positive revisions perform conditional changes. Configure literal `ownership.keys` and the optional override model before provider boot. Native adoption accepts reviewed mappings over populated native storage.

HTTP controllers, requests, editor projections, select options and frontend types belong to applications. The package publishes no management routes or consumer type metadata. Active HTTP regression fixtures demonstrate application adaptation under `tests/Fixtures` and are excluded from the package archive.

## Installation

```bash
composer require nvl/translations:^6.0
php artisan migrate
```

Configure `config/nvl-translations.php` and choose one migration owner: automatic loading (`migrations.enabled=true`), or published host migrations with automatic loading disabled. CLI workflows use `SystemActor::Background` and require the same explicit host policy as native calls. Source workspace operations are unavailable inside owner or public-site execution.

## File formats

Both native Laravel formats are independent and may be synchronized together or separately:

```text
lang/
├── en/
│   ├── messages.php
│   └── validation.php
├── bg/
│   └── messages.php
├── en.json
└── bg.json
```

- PHP catalogs use locale directories and nested arrays.
- JSON catalogs use one `<locale>.json` object per locale.
- PHP rows retain their group path, such as `messages` or `admin/actions`.
- JSON rows use Laravel's full source string as the key.
- Only valid UTF-8 string or null leaf values are managed. Booleans and numbers are rejected instead of being silently coerced. Nested PHP arrays are flattened and reconstructed, including numeric keys at the top level or inside arrays; empty arrays contain no translation leaves.
- Dots in PHP leaf identities represent nesting. Literal dots or empty strings inside an individual PHP array-key segment are rejected because they cannot be round-tripped without changing the array shape.


## Configure source locations

The application source defaults to Laravel's `lang_path()`, which is the root `lang` directory in Laravel 13:

```php
'paths' => [
    'app' => lang_path(),
    'vendor' => lang_path('vendor'),
],

'module_roots' => [],

'discovery' => [
    'modules' => false,
    'vendor' => false,
],
```

The conventions discovered automatically are:

- `app`
- `module:<ModuleName>` from `Modules/<Module>/lang` or `Modules/<Module>/Resources/lang`
- `vendor:<package>` from `lang/vendor/<package>`

Module and vendor discovery are disabled by default. Mail files remain ordinary application PHP groups (`mail` or `mail/*`), not a second overlapping storage scope.

Add explicit module roots before enabling module discovery:

```php
'module_roots' => [
    base_path('Modules'),
],
```

Additional configured file roots become `custom:<name>` scopes:

```php
'custom_scopes' => [
    'shared' => base_path('translations/shared'),
    'frontend' => resource_path('locales'),
],
```

Every configured path must be an absolute local directory. Scope tokens and locale names are validated; HTTP and CLI input is never interpreted as an arbitrary output path.


## Import files into the editable catalog

```bash
php artisan nvl:translations:sync
php artisan nvl:translations:sync --format=php
php artisan nvl:translations:sync --format=json
php artisan nvl:translations:sync --scope=app --scope=custom:shared --format=both
```

Programmatic use:

```php
$result = app(\Nvl\Translations\Services\TranslationImporter::class)->import(
    actor: $actor,
    scopeTokens: ['app'],
    format: 'both',
);
```

The result reports scopes, files, entries, created rows, updated rows, preserved database edits, conflicts, newly missing rows, and warnings.

Import is read-first and mutation-second. With strict mode enabled, a malformed source file stops synchronization before any selected scope changes in the database:

```php
'import' => [
    'fail_on_error' => true,
    'conflict_strategy' => 'fail',
],
```

Conflict strategies:

- `fail` rolls back the selected synchronization and throws a conflict exception, producing a non-zero command exit code.
- `prefer_file` uses the current file value.
- `prefer_database` preserves the editable workspace value.
- `--strategy=interactive` prompts for one of those strategies in an interactive terminal.

Missing configured directories are reported and skipped. A successfully read selected format marks rows absent from that source as missing; parse errors never cause missing markers.

PHP catalog loading is output-buffered: a catalog that emits output is rejected instead of corrupting a console or API response. Files or directories reached through symbolic links are rejected.


## Configure output directories

`source` writes back to each scope's configured source directory. Additional output locations are named, trusted maps:

```php
'export_targets' => [
    'source' => [],

    'generated' => [
        'app' => storage_path('translations/generated/app'),
        'custom:shared' => storage_path('translations/generated/shared'),
    ],
],
```

A named target must explicitly map every selected scope. This supports generated directories, deployment staging, frontend handoff directories, or other application-owned locations without accepting filesystem paths from requests.

Named destinations must be distinct from every source scope, the backup directory, and each other. Duplicate discovered scope tokens that point at different roots fail configuration validation.


## Resave files

```bash
php artisan nvl:translations:export --dry-run
php artisan nvl:translations:export --scope=app --format=php --force
php artisan nvl:translations:export --scope=app --format=json --locales=en,bg --force
php artisan nvl:translations:export --scope=app --target=generated --format=both --force
```

Programmatic use:

```php
$result = app(\Nvl\Translations\Services\TranslationExporter::class)->export(
    actor: $actor,
    scopeTokens: ['app'],
    locales: ['en', 'bg'],
    format: 'both',
    target: 'generated',
    prune: false,
);
```

Exports:

- rebuild nested PHP arrays from dot keys;
- sort groups and keys deterministically;
- emit strict PHP files and pretty UTF-8 JSON;
- create missing target directories;
- stage and validate every replacement before changing any target file;
- apply sibling-file atomic replacements as one backed-up batch and restore original contents if a later operation fails;
- preserve existing file permissions when possible;
- never write outside the configured scope target.

A non-dry-run export must start outside an existing transaction on the actual `TranslationEntry` connection. The service rejects an ambient catalog transaction before source synchronization, backups or file publication; its immediate filesystem result cannot be undone by a caller’s later database rollback. This applies to source and named destinations. Dry runs may simulate the export inside an ambient transaction and restore their own nested changes without publishing files.

Every export performs a fresh authoritative-source read first. Export stops when that read is incomplete, including when best-effort importing is enabled, so an unreadable source file cannot be overwritten from a partial workspace.

Exporting to `source` updates the synchronization hash because the imported source was replaced. Exporting to another named target does not alter source tracking, so a later source import still preserves unexported database edits.


## Pruning

Pruning is opt-in because it deletes stale translation files:

```bash
php artisan nvl:translations:prune \
    --scope=app \
    --locales=en,bg \
    --format=both \
    --target=generated \
    --dry-run
```

Pruning is constrained to the selected configured target, scopes, locales, and formats:

- PHP pruning only considers `.php` files below the selected locale directory.
- JSON pruning only considers the selected `<locale>.json` file.
- Other extensions and unselected locales are untouched.


## Source usage scanning

Configure the application source paths and extensions scanned for literal Laravel translation keys:

```php
'scan' => [
    'paths' => [
        base_path('app'),
        base_path('extensions'),
        resource_path('views'),
        resource_path('js'),
    ],
    'extensions' => ['php', 'blade.php', 'js', 'jsx', 'ts', 'tsx', 'vue'],
    'retention_days' => 30,
    'namespaces' => [
        'content' => 'module:Content',
        'package-ui' => 'vendor:package-ui',
    ],
],
```

```bash
php artisan nvl:translations:scan
php artisan nvl:translations:unused --help
```

Dynamic keys cannot be discovered statically. Preserve them in unused reports with `nvl-translations.scan_allowlist`.

The scanner is intentionally heuristic: it records only configured literal-key call patterns. The package defaults cover Laravel helpers, `Lang::get`, `Lang::choice`, Blade `@lang`/`@choice`, and JavaScript `t`/`$t`; override `scan.patterns` only with tested regular expressions. Non-namespaced usages belong to the `app` scope; an unknown namespace is skipped rather than treated as a global usage. Successful scans prune usage history older than `scan.retention_days` when retention is greater than zero.

Each successful scan stores a durable run marker, including zero-hit scans. Latest-scan unused reports therefore do not reuse stale hits, and usage matching keeps PHP and JSON identities separate. Ambiguous namespace names must be resolved explicitly through `scan.namespaces`.

Workspace synchronization uses an atomic cache lock:

```php
'lock' => [
    'store' => 'redis', // null uses the application default
    'seconds' => 300,
    'wait_seconds' => 0,
],
```

All application nodes must use the same lock-capable cache store. Size the lock lifetime above the longest expected import, export, or scan.


## Operational workflow

The safe database-editing cycle is:

```bash
php artisan nvl:translations:sync --scope=app --format=both
# Edit rows through the management application.
php artisan nvl:translations:export --scope=app --target=source --format=both --force
git diff -- lang
php artisan nvl:translations:sync --scope=app --format=both
```

Review file diffs before committing. Use `--prune` only when the database catalog is intentionally authoritative for the selected destination.

Inspect installation and workspace status without mutation:

```bash
php artisan nvl:translations:status --format=json
php artisan nvl:translations:doctor --strict --format=json
```


## Shared infrastructure options

Set `nvl-translations.locks.store`, or inherit `nvl-core.locks.store` and `cache.default`. The historical `nvl-translations.lock.store` key remains supported for one major cycle and is reported through Core diagnostics. Workspace process locking uses the selected store; `lock.seconds` and `lock.wait_seconds` retain their operation-specific behavior.


## Canonical configuration ownership

Use `nvl-translations` settings in `config/nvl-translations.php` and canonical package environment names. Old generic roots are foreign unless an upgrading NVL host explicitly selects them in Core's default-off compatibility. Canonical false/null/empty values win; no old roots are populated or written back. Keep logical package/resource IDs unchanged. Review [Core's rename inventory and cache/worker cutover](https://github.com/nvl-laravel-suite/core/blob/main/UPGRADING.md#major-5-canonical-configuration-and-environment).


## Error codes and events

All recognized package failures implement `Nvl\Support\Contracts\PackageException`; only `RespondableException` opts into safe response metadata. Keep native PHP programmer errors and Laravel/SDK exceptions distinct. The optional `PackageExceptionRenderer` is registered by the host in `withExceptions`; it leaves unrelated, marker-only and non-JSON handling to the host. Its JSON envelope is `{message:string, code:string, context:object}`. Request locale is host-owned; diagnostics/previous exceptions are not public copy. Event schemas and source connections are documented in [events](docs/events.md).

The table lists enum discriminators, including any successful codes retained for compatibility. A code is not itself an HTTP status; the throwing exception's `suggestedStatus()` is authoritative, especially legacy/custom constructors. Empty context renders as `{}`; only documented JSON-safe context is presented.

| Code | Suggested status | Public context | Translation key |
| --- | --- | --- | --- |
| `operation_failed` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-translations::responsecode.operation_failed` |
| `invalid_translation_input` | 422 | Declared safe scalar/array map; otherwise `{}` | `nvl-translations::responsecode.invalid_translation_input` |
| `stale_translation_workspace` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-translations::responsecode.stale_translation_workspace` |
| `translation_sync_conflict` | 409 | Declared safe scalar/array map; otherwise `{}` | `nvl-translations::responsecode.translation_sync_conflict` |
| `translation_workspace_locked` | 423 | Declared safe scalar/array map; otherwise `{}` | `nvl-translations::responsecode.translation_workspace_locked` |
| `updated` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-translations::responsecode.updated` |
| `imported` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-translations::responsecode.imported` |
| `exported` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-translations::responsecode.exported` |
| `scanned` | Exception-defined; see `suggestedStatus()` | Declared safe scalar/array map; otherwise `{}` | `nvl-translations::responsecode.scanned` |



## License

Released under the [MIT License](LICENSE).

Shared [service contracts, model extensions and composition](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/consumer-api.md) apply alongside the package-specific rules above.
