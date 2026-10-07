# NVL Translations — API and usage

## Quickstart

```sh
composer require nvl/translations:^5.0
php artisan nvl:install translations --dry-run
php artisan nvl:install translations
```

Required NVL dependencies: `nvl/core` (`^5.0`), `nvl/filterable` (`^5.0`). Configure explicit application source roots before scanning. Catalog scan/usage records are platform vocabulary; tenant overrides need explicit admitted ownership.
Review the published common config, select one migration owner, and run schema preflight before existing-table upgrades. The installer does not enable features or run migrations. Follow the detailed installation and capability sections below before invoking a storage/provider operation.

Inject `Nvl\Translations\Contracts\ScanTranslationsContract` in a host service. After supplying the trusted inputs described above, the first public call is:

```php
use Nvl\Translations\Contracts\ScanTranslationsContract;

/** @var ScanTranslationsContract $capability */
$result = $capability->execute();
```

Use the [event catalog](docs/events.md) and [Testing your app](#testing-your-app) below. The suite [getting-started guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/getting-started.md) provides a complete Comments host fixture; package archives retain their own local references.


[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

For support, [open an issue](https://github.com/nvl-laravel-suite/translations/issues). For vulnerabilities, use
[private reporting](https://github.com/nvl-laravel-suite/translations/security/advisories/new). See [Contributing](CONTRIBUTING.md).

See the [installation and publishing guide](https://github.com/nvl-laravel-suite/laravel-suite/blob/main/docs/installation.md) for Composer setup, configuration, migration ownership, and agent skills.

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/translations:^5.0` |
| Module identifier | `nvl/translations` |
| PHP namespace | `Nvl\Translations` |
| Service provider | `Nvl\Translations\Providers\TranslationsServiceProvider` |
| Configuration | `config/nvl-translations.php` |

The suite's Laravel 12–13 file-catalog module for reading, scanning, editing, synchronizing, and resaving PHP-array and JSON translation files.

Use `nvl/translatable` for locale-specific Eloquent content. This package only manages Laravel language files.

## Purpose and boundary

The workflow is deliberately simple:

1. Read translation files from configured source directories.
2. Synchronize each leaf string into editable database rows.
3. Edit those rows through package Actions or the optional management API.
4. Resave the rows as valid Laravel PHP or JSON translation files.

The database is an editing and synchronization workspace. It is not installed as Laravel's runtime translation loader, does not override file lookup precedence, and does not store translatable model content.

## Requirements and installation

- PHP 8.4+
- Laravel 12–13
- `nvl/core`, `nvl/filterable`, and `nvl/tenancy`

```bash
composer require nvl/translations:^5.0
php artisan migrate
php artisan vendor:publish --tag=nvl-translations-config
php artisan vendor:publish --tag=nvl-translations-skills
```

Choose exactly one migration owner. For automatic vendor loading, leave
`nvl-translations.migrations.enabled=true` and do not publish
`nvl-translations-migrations`. For host-owned migrations, run
`php artisan vendor:publish --tag=nvl-translations-migrations`, set
`nvl-translations.migrations.enabled=false` before the first migration, and
maintain the copied files as application migrations. Never run both sources;
Laravel retimestamps published migrations.

The package supplies English and Bulgarian validation copy. Publish overrides when needed:

```bash
php artisan vendor:publish --tag=nvl-translations-translations
```

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

The application source defaults to Laravel's `lang_path()`, which is the root `lang` directory in Laravel 12–13:

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
$result = app(ImportTranslationsContract::class)->execute(
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

## Edit database rows

Use the validated DTO and Action. Supply `$expectedRevision` from the authorized
management projection; do not read undeclared fields from the TranslationEntry
identity handle. Preserve the revision when submitting the edit:

```php
$entry = app(UpdateTranslationEntryContract::class)->execute(
    entry: $entry,
    data: UpdateTranslationEntryPayload::validateAndCreate([
        'value' => 'Save changes',
        'expectedRevision' => $expectedRevision,
    ]),
);
```

`ListTranslationEntriesAction` provides paginated, filterable administrative reads. `ListTranslationFilterOptionsAction` returns available scope, locale, and PHP-group filters.

Edits use both a database row lock and the same workspace lock as synchronization, and require the current optimistic revision. The source hash is intentionally not replaced by a database edit. A later import can therefore distinguish an unsaved database edit from an unchanged source file.

## Catalog statistics and shared filters

Use the public schema service when an application owns its management controller.
This avoids constructing `TranslationEntry` only to discover the package's filter
allowlist and keeps list and statistics inputs identical:

```php
use Nvl\Filterable\Http\QueryFilterSetFactory;
use Nvl\Translations\Contracts\GetTranslationCatalogStatisticsContract;
use Nvl\Translations\Services\TranslationEntryFilterSchema;

$schema = app(TranslationEntryFilterSchema::class)->make();
$filters = app(QueryFilterSetFactory::class)->fromQuery(
    request()->query(),
    $schema,
);

$statistics = app(GetTranslationCatalogStatisticsContract::class)->execute($filters);
```

`GetTranslationCatalogStatisticsAction::execute(?FilterSet $filters = null)`
authorizes `TranslationsAbility::ListEntries` before its first query and returns
`TranslationCatalogStatisticsData`. The projection contains `total`, `missing`,
`conflicts`, `changed`, `locales`, and `scopes`:

- `missing` counts rows whose durable `is_missing` marker is true.
- `conflicts` counts rows whose synchronization status is `conflict`.
- `changed` counts rows with a source hash whose durable status is `edited` or
  `conflict`. A preserved database edit remains `edited`; “preserved” is an
  import result counter, not a stored synchronization status.
- `locales` and `scopes` are `array<string, int>` maps sorted by count descending
  and key ascending, capped at 100 entries each. Scope keys use command-compatible
  tokens such as `app`, `module:Website`, and `custom:shared`. JSON serialization
  always emits both maps as objects, including valid numeric-only locale keys.

The statistics read executes one scalar aggregate and one grouped query per
dimension: three queries regardless of catalog size. Caller-provided sort clauses
do not affect aggregates; every allowlisted filter is applied with the same
semantics as `ListTranslationEntriesAction`.

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
$result = app(ExportTranslationsContract::class)->execute(
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

## Optional management API

The API defaults to `nvl/api/v1/translations`:

```php
'routes' => [
    'enabled' => true,
    'prefix' => 'nvl/api/v1',
    'middleware' => ['api'],
    'management_middleware' => ['auth'],
],
```

It provides list, row update, import, export, and scan endpoints. Entry updates require both `value` and `expectedRevision`. Import/export requests accept bounded configured scope-token lists, `php|json|both`, locale filters, a named export target, and the explicit prune flag. Every non-dry-run API export requires `force=true`; pruning additionally authorizes the independent `prune` ability. Stale revisions and source conflicts return `409`, workspace locks return `423`, and unsafe public inputs return `422`. Applications may add Sanctum, verification, permissions, throttling, or response middleware.

Disable package routes when the consumer owns its management controllers:

```php
'routes' => [
    'enabled' => false,
],
```

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

## TypeScript, skill, and quality

## Tenant copy overrides

Source scans, imports, updates, and exports are platform-only. Tenant copy uses
the separate allowlisted override repository and exports private artifacts to
`tenants/<tenant>/translations/<artifact>.json`; it never rewrites source files
or mutates Laravel's global translator.

DTOs and enums register with Core's Data provider; configured type generation includes them automatically. Publishing `nvl-translations-skills` installs package-specific agent guidance.

From a standalone checkout of the public Translations repository:

```bash
composer install
composer quality
```

`composer quality` checks Pint formatting, Larastan, and the isolated Testbench/Pest suite.

## Supported PHP usage

The source `@api` declarations identify supported workflows, extension contracts, and value types. Public members marked `@internal` and untagged implementation types remain package-owned. Concrete Actions retain their existing constructors, qualifiers, and `execute()` signatures.

A package model returned or accepted by a public workflow is an identity/result handle. Use its declared type and `getKey()`, `getKeyName()`, `getMorphClass()`, `getRouteKey()`, `getRouteKeyName()`, `is()`, `isNot()`, and `relationLoaded()`. Read only explicitly declared in-memory `@nvl-consumer-read` fields; ordinary model PHPDocs and fillable attributes do not grant consumer reads. Obtain display projections through public reads. Persistence, additional model queries, relation access/loading, and generic model serialization are outside this contract. Host-model queries remain available, while traversal or aggregates of package capability relations require the package public reader or authorized adapter.

## Testing your app

Inject the nine focused catalog/import/export/scan contracts. Existing
`ImportTranslationsContract`, `ScanTranslationsContract`, and
`UpdateTranslationEntryContract` remain authoritative; no parallel importer or
synchronization API is introduced. Concrete workflows retain native arguments,
array shapes, scanner behavior, and internal execution chains.

```php
use Nvl\Translations\Contracts\UpdateTranslationEntryContract;
use Nvl\Translations\Data\UpdateTranslationEntryPayload;
use Nvl\Translations\Models\TranslationEntry;

final readonly class EditTranslation
{
    public function __construct(private UpdateTranslationEntryContract $entries) {}

    public function run(TranslationEntry $entry, UpdateTranslationEntryPayload $data): TranslationEntry
    {
        return $this->entries->execute($entry, $data);
    }
}

$entry = new TranslationEntry;
$data = new UpdateTranslationEntryPayload('Hello', 1);
$entries = Mockery::mock(UpdateTranslationEntryContract::class);
$entries->shouldReceive('execute')->once()->with($entry, $data)->andReturn($entry);
$this->app->instance(UpdateTranslationEntryContract::class, $entries);
expect($this->app->make(EditTranslation::class)->run($entry, $data))->toBe($entry);
```

The native model is an unsaved identity/result fixture. Use the real import/edit
workflows on the configured package schema to test revisions, authorization,
source discovery, and tenant overrides. Use temporary source/output directories
for real scanners and source-file export tests; `Storage::fake()` applies to
filesystem-disk operations such as tenant exports. A mocked scan/export result
does not prove source or file behavior. Consumer tooling is the shipped PHPStan
extension below; no Suite workbench command is needed.

In Laravel application tests, register a native Mockery interface mock or a small
implementation with `$this->app->instance(Contract::class, $substitute)` before
resolving your application service. A host binding installed before package
registration is retained; later contract replacements affect subsequent
resolutions. Rebuild previously resolved host services after replacing their
dependencies. Concrete implementations remain callable with their original
constructors through major 5. Mocks exercise your application orchestration;
package authorization, persistence, and external effects need real integration
tests.

The existing ImportTranslationsContract, ScanTranslationsContract, and UpdateTranslationEntryContract are reused. Six additional selected workflows gain focused contracts without changing scanner/export/import behavior.

For static consumer checks, include the shipped
[`consumer-audit.neon`](https://github.com/nvl-laravel-suite/core/blob/main/support/consumer-audit.neon) from
`vendor/nvl/core/support/consumer-audit.neon` in your host PHPStan configuration
and configure explicit `nvlConsumer.testPaths` for factory-backed tests. The
extension checks supported APIs and model/query boundaries; it does not prove
authorization or arbitrary dynamic SQL.

## Shared consumer diagnostics

Run `php artisan nvl:doctor --strict --format=json` to combine the read-only checks from loaded NVL package providers. Errors fail the gate, and strict mode also fails warnings. This package's existing Doctor command remains available and uses the same package-owned inspection service.


## Shared infrastructure options

Set `nvl-translations.locks.store`, or inherit `nvl-core.locks.store` and `cache.default`. The historical `nvl-translations.lock.store` key remains supported for one major cycle and is reported through Core diagnostics. Workspace process locking uses the selected store; `lock.seconds` and `lock.wait_seconds` retain their operation-specific behavior.

## Next major: isolated schema identities

Use `nvl-translations.tables.<logical-key>` for every table and `nvl-translations.connection` for its database connection. Null connection inherits `nvl-core.connection`, then Laravel's default. Tables are resolved at runtime by the package table definition helper.

| Logical key | New default | Previous name |
| --- | --- | --- |
| `entries` | `nvl_translations_entries` | `translation_entries` |
| `scan_runs` | `nvl_translations_scan_runs` | `translation_scan_runs` |
| `usages` | `nvl_translations_usages` | `translation_usages` |
| `tenant_overrides` | `nvl_translations_tenant_overrides` | `tenant_translation_overrides` |

Migration filenames contain `nvl_translations_`. Existing installations must complete the upgrade in `UPGRADING.md` before running new migrations. A pending creator rejects an existing target before that owned migration runs; use `nvl:schema:preflight` for an explicit whole-batch check; legacy storage with old history needs an ownership decision.

## Canonical configuration ownership

Use `nvl-translations` settings in `config/nvl-translations.php` and canonical package environment names. Old generic roots are foreign unless an upgrading NVL host explicitly selects them in Core's default-off compatibility. Canonical false/null/empty values win; no old roots are populated or written back. Keep logical package/resource IDs unchanged. Review [Core's rename inventory and cache/worker cutover](https://github.com/nvl-laravel-suite/core/blob/main/UPGRADING.md#major-5-canonical-configuration-and-environment).

## Testing your app

Inject the supported contract rather than constructing its concrete Action or querying package tables. Replace `Nvl\Translations\Contracts\ScanTranslationsContract` in Laravel's native container for a host-workflow test:

```php
use Nvl\Translations\Contracts\ScanTranslationsContract;

$double = Mockery::mock(ScanTranslationsContract::class);
$this->app->instance(ScanTranslationsContract::class, $double);
// Configure the exact execute arguments and documented return value for your host case.
```

The package's conditional native binding preserves host substitutions. Production uses the real contract; test doubles do not prove its storage/authorization behavior.

A detached fixture for a returned identity/data handle is:

```php
use Nvl\Translations\Models\TranslationEntry;
$fixture = TranslationEntry::factory()->withoutParents()->make();
```

Ordinary `make()` may persist declared package parents. `withoutParents()->make()` disables parent expansion/admission for detached fixtures; use explicit persisted parents/owners and matching effective connections for a real `create()`. Factories do not authorize workflows, call Stripe, create backing Media objects or publish Template artifacts. Enabled tenancy requires explicit admitted persisted tenants/parents. Your host test installation supplies Faker; no test runner is a runtime package dependency.

Use Laravel `Event::fake()`, `Queue::fake()`, `Mail::fake()` or `Storage::fake()` only for the effects the host test intends to isolate. Use real commits/listeners for timing proof. Add the optional Core consumer boundary rules to host PHPStan:

```neon
includes:
    - vendor/nvl/core/support/consumer-audit.neon
parameters:
    nvlConsumer:
        testPaths: [tests]
        tableNames: []
        exceptions: []
```

Rules read installed public metadata without suite boot. They flag internal symbols, package model queries/writes, capability relations and owned tables; they cannot prove dynamic code or runtime authorization. Exact exceptions require `file`, `identifier`, `symbol`, and a documented `reason`. New C3/C4/E tests, archives and guide execution remain pending until the integration phase records results.

### Shipped factory states

These runtime builders keep Laravel's native Factory API. The listed methods name explicit supported parent/owner/lifecycle states; follow each factory's native admission requirements. Detached examples above do not assert persistence validity.

| Factory | Explicit states |
| --- | --- |
| [`TenantTranslationOverrideFactory`](database/factories/TenantTranslationOverrideFactory.php) | Native Factory states only |
| [`TranslationEntryFactory`](database/factories/TranslationEntryFactory.php) | Native Factory states only |
| [`TranslationScanRunFactory`](database/factories/TranslationScanRunFactory.php) | Native Factory states only |
| [`TranslationUsageFactory`](database/factories/TranslationUsageFactory.php) | `forScan(TranslationScanRun $parent)` |

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
