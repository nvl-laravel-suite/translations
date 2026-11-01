# Translations consumer recipes

See [installation](installation.md) for provider/migration/publishing configuration and [API](api.md) for complete signatures, criteria and outputs.

## Separate source workspace from owner copy

The source workspace edits application/module/vendor/custom PHP/JSON catalogs in central or unowned execution. It is unavailable from an admitted owner or public-site scope. Per-owner copy uses `TranslationOverrideRepository` in an admitted native owner scope. Do not use source exports as an owner override mechanism.

Bind `TranslationsAuthorization::authorize(TranslationsAbility $ability, Authenticatable|SystemActor $actor, ?TranslationEntry $entry = null): void`, or configure `nvl-translations.authorization.ability` for the host's human Gate. Default access is denied and a background enum needs explicit host permission. These APIs require persisted Eloquent actors (or explicit system actors); the service reloads canonical actor storage, so dirty attributes, deleted actors or altered model storage cannot grant authority.

## Configure trusted scopes and targets

Default application files live at `lang_path()`. Module/vendor discovery is disabled by default. Enable it deliberately after choosing `module_roots`/vendor paths. Additional roots are named custom scopes; every configured path is absolute and local.

```php
config([
    'nvl-translations.custom_scopes.shared' => base_path('translations/shared'),
    'nvl-translations.export_targets.generated' => [
        'app' => storage_path('translations/generated/app'),
        'custom:shared' => storage_path('translations/generated/shared'),
    ],
]);
```

The host provisions source files and permissions. Scope tokens are `app`, `module:Name`, `vendor:package`, `custom:shared`; empty scope selection uses configured discovery. `source` is the built-in export destination. A named target must explicitly map every selected scope, stay distinct from sources/backups/other targets, and cannot be supplied as a filesystem path by a request. Symlink traversal rejects. These are host configuration decisions, not input fields for the service to discover from arbitrary user paths.

PHP catalogs live under locale/group files and flatten nested array identities; JSON catalogs use `<locale>.json` and full source-string keys. Valid UTF-8 string/null leaves are managed. Boolean/numeric leaves reject. PHP key segments cannot contain literal dots or empty text because dot paths represent nesting; numeric array keys are supported. PHP files emitting output reject instead of corrupting a response.

## Synchronize, edit and export

Assume `$actor` is an admitted explicit principal authorized for source-workspace abilities and source `lang/en/messages.php` already exists:

```php
use Nvl\Translations\Services\TranslationCatalog;
use Nvl\Translations\Services\TranslationExporter;
use Nvl\Translations\Services\TranslationImporter;

$import = app(TranslationImporter::class)->import(
    actor: $actor,
    scopeTokens: ['app'],
    format: 'both',
    dryRun: false,
);
$catalog = app(TranslationCatalog::class);
$page = $catalog->paginate($actor, [
    'locale' => 'en',
    'format' => 'php',
    'group' => 'messages',
    'sort' => ['key'],
], 25);
$entry = $page->first();
if ($entry !== null) {
    $updated = $catalog->update($actor, $entry, [
        'value' => 'Updated copy',
        'expected_revision' => $entry->revision,
    ]);
}
$plan = app(TranslationExporter::class)->export(
    actor: $actor,
    scopeTokens: ['app'],
    locales: ['en'],
    format: 'both',
    target: 'generated',
    dryRun: true,
);
```

Run a real export with `dryRun: false` only when the host operation intends publication. `format` is exactly `php`, `json` or `both`; locales are a string list or null for discovery. Output is an array containing counts plus actual `paths`, not one download file. Import summary fields are `scopes`, `files`, `entries`, `created`, `updated`, `preserved`, `conflicts`, `missing`, `warnings`. Export summary fields are `scopes`, `locales`, `files`, `deleted`, `target`, `paths`.

Catalog criteria are `scope_type`, `scope_name`, `locale`, `format`, `group`, `key`, `key_contains`, `search`, `missing_value`, `changed_since_import`, `is_missing`, `sort`. The first six identity fields accept a string or a list of at most 100 strings; `key_contains`/`search` require one string and the three state fields require booleans. Sort is a list of at most nine allowlisted column names, optionally prefixed `-`; its default is `['-updated_at']` and ID is appended as a tie-breaker. Pagination defaults to 25 and clamps to 1–200; page starts at one. Updates accept exactly `value:string|null` and positive integer `expected_revision`. Null explicitly clears; omission is invalid. The service locks and re-authorizes the canonical entry on its actual connection. Stale revisions raise `StaleTranslationWorkspaceException`.

Imports read selected files before mutation. Default `import.fail_on_error=true` and `conflict_strategy=fail` stop/roll back the selected synchronization. Explicit `prefer_file` or `prefer_database` changes the resolution policy; do not choose it silently. Missing directories are warnings; parse failures do not manufacture missing markers. Successfully read selected sources mark absent entries missing. Keep edited workspace values distinct from source hashes when deciding conflicts.

## Publication, rollback and operational commands

Every export performs a fresh authoritative-source import. Incomplete reads block export even with best-effort imports enabled. Locale discovery and compilation read writer storage after sync. The writer stages and validates every replacement, creates backups, uses sibling atomic rename and restores previous files if a later operation fails. Restoration is best effort; report an incomplete rollback and retain evidence for host recovery rather than retrying blindly.

Real export rejects an existing transaction on the catalog connection before publication, because a later database rollback cannot retract filesystem artifacts. A dry run nests its simulation and rolls it back without writing files. Exporting to `source` updates synchronization hashes; named targets do not pretend source files were changed. Other ordinary catalog mutations respect their actual model connection and enclosing transaction.

```sh
php artisan nvl:translations:sync --scope=app --format=both
php artisan nvl:translations:export --scope=app --target=generated --dry-run
php artisan nvl:translations:scan
php artisan nvl:translations:status
php artisan nvl:translations:doctor --strict --format=json
```

CLI uses `SystemActor::Background` and needs explicit host authorization. `export --force` publishes without an interactive prompt; it is not an authorization bypass. `nvl:translations:prune --scope=app --target=generated --dry-run` previews deletion constrained to selected scopes/locales/formats. Applying prune requires `--force`; unselected formats/locales and other extensions remain outside the plan. Inspect `--help` for selection/output flags rather than inventing commands.

Workspace locking uses configured `locks.store` (or the host cache default), `lock.seconds` (300) and `lock.wait_seconds` (0). Choose a shared atomic lock store for concurrent processes. Defaults are operational settings, not a durable exactly-once outbox. Keep backup storage and failure recovery in deployment operations.

## Literal usage scanning and unused reports

`TranslationScanner::scan($actor)` scans configured roots/extensions/patterns and stores a durable successful-run marker, even for zero hits. Default extensions cover PHP/Blade/JS/JSX/TS/TSX/Vue; default literal patterns cover Laravel helpers/Blade and JavaScript `t`/`$t`. Dynamic keys cannot be discovered statically: keep reviewed patterns in `scan_allowlist`. Resolve namespaced usages explicitly through `scan.namespaces`; unknown namespaces are skipped, not treated as global usage.

`TranslationCatalog::unused($actor, ['app'], days: 30)` returns a native unused report; `nvl:translations:unused --help` describes CLI output/selection. Successful scans prune history older than positive `scan.retention_days` (default 30). Latest-scan reporting uses the run marker rather than stale hits and keeps PHP/JSON identities separate. Scanning is a heuristic input to review, not proof a dynamic string can be safely deleted.

## Native owner overrides

Before provider boot configure literal keys, for example `nvl-translations.ownership.keys=['checkout.title']`, and optionally `models.override` with a compatible subclass of `TranslationOverride`. The host provisions/adopts native owner storage and enters its admitted owner runner. A null context, tenant-named column or legacy bridge grants nothing. Preserve unique `(owner_scope_key,key,locale)` identity and required model casts/lifecycle hooks.

Inside that authorized context, for a key/locale known to be absent:

```php
use Nvl\Translations\Contracts\TranslationOverrideRepository;

$overrides = app(TranslationOverrideRepository::class);
$overrides->set($actor, 'checkout.title', 'en', 'Checkout', 0);
$text = $overrides->get($actor, 'checkout.title', 'en');
```

Zero asserts creation and produces revision one; concurrent existing data rejects. Later writes/deletes require the exact positive revision supplied by the host's authorized revision-aware projection; `get` returns only `?string`, not a revision record. `set`/`forget` return void, lock on the configured model connection and preserve hooks/casts. Values must be valid UTF-8 strings; deletion uses `forget`, not null. Literal keys are bounded and cannot contain wildcards or file path separators.

`export($actor, $privateDisk)` returns `owner`, `disk`, `path`, `sha256`, `size` for a private owner artifact. The host chooses the trusted disk and download authorization. Optional native Ownership can be physically absent for source operations; owner overrides require its native authority rather than silent global fallback.

## Errors and verification

Handle invalid native input, source/path/configuration failures, workspace lock contention, `TranslationConflictException`, stale workspace revisions and authorization failures at the application boundary. Test real PHP/JSON round trips, UTF-8/nulls, conflicts, missing markers, stale revisions, writer-source reads, staging/rollback and containment. Test actor revocation and private owner scope with configured models. Use focused host tests during iteration; no package HTTP routes or Data/TypeScript runtime are required.
