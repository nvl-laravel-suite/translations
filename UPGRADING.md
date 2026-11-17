# Upgrading NVL Translations to Light

Upgrade the selected dependency graph together to 7.x on Laravel 13 and Testbench 11.

Replace Action/DTO contracts with `TranslationImporter::import`, `TranslationExporter::export`, `TranslationScanner::scan`, and `TranslationCatalog::{paginate,update,statistics,unused}`. Inputs use native scalars and documented arrays; update requires the `value` key (nullable) and a positive `expected_revision`. Pass an explicit persisted `Authenticatable` or `SystemActor` and bind the host authorization policy. A system identity grants no permission by itself.

Owner overrides now evaluate policy using canonical persisted actor facts and reject invalid UTF-8 values before persistence. Updates and deletes honor configured model casts and lifecycle hooks inside revision-checked transactions on the model connection. Hook failures roll back; enclosing application transactions remain supported.

Exports read locales and PHP/JSON catalog values from the writer after source synchronization. A lagging read replica cannot replace current edits with stale values. Configure source storage with `nvl-translations.connection` and process lock storage with `nvl-translations.locks.store`; legacy aliases are removed. These service fixes require no schema migration.

Source roots and export destinations remain configured and path-guarded. Export returns counts plus a list of artifact paths. Keep application-owned HTTP validation, responses, editor/select projections and type generation in the host. Package routes, DTOs, Action interfaces, frontend metadata and legacy Tenancy integration are removed.

Owner copy continues through `TranslationOverrideRepository` inside native Ownership execution. Explicit native adoption preserves populated override rows, IDs, values, revisions and timestamps. It no longer discovers or converts an old tenant table automatically; plan any historical data migration explicitly in the host. Keep migration history and reviewed provenance.

`TranslationEntryUpdated::entry` is now a detached array snapshot. Drain old queued payloads and restart workers when updating listeners. Events publish after the actual source connection commits; filesystem completion and SQL transaction completion remain separate facts.

Adoption runs through nvl:adopt with reviewed, checkpointed batches and an explicit activate step. Committed batches survive a failure. Keep writers stopped until verify and activate pass. Run `php artisan nvl:adopt translations plan|apply|verify|activate <manifest.json>` with the options `TranslationCatalogAdopter` takes (`connection`, `entries_table`, `usages_table`, `source_values`); entries are staged before usages, and a staged row that drifts from its reviewed baseline blocks resume. `TranslationCatalogAdopter::plan()`, `apply()` and `verify()` remain available unchanged.

### Unreleased

- Requires `nvl/core` 7.3.0. A translations adoption run resumes only when every stored checkpoint still matches its staged rows; restore a deleted or changed staged row, or start a new run, before resuming.
- A workspace operation inside your own open transaction now keeps the workspace lock until that transaction commits or rolls back; other processes wait or receive `TranslationWorkspaceLockedException` meanwhile. Keep such transactions short.
- Replace symbolic links below language, scan and export roots with real directories; import, scan and export now reject them.
- Rescan after upgrading: keys with surrounding whitespace or a literal `$` are now recorded exactly, so unused reports for them change.
- A model observer that vetoes a `TranslationEntry` or `TranslationScanRun` save now makes the operation throw `TranslationsException` instead of reporting success.
- Translation keys and values containing a NUL byte are now rejected on import (a warning, or a stopped import with `import.fail_on_error`), catalog edit and override write. Remove NUL bytes from language files before syncing.
- Rescan after upgrading: the default `scan.patterns` now also record JavaScript template-literal keys without `${` and PHP `key:` named arguments, so unused reports shrink. Hosts that publish their own `scan.patterns` keep their patterns; copy the new defaults from the package config to gain these forms.

See [README](README.md) for native signatures and retained source, scan, backup and lock configuration.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published). In family 7, `nvl:doctor` requires registering `Nvl\Support\Providers\DoctorServiceProvider` in the host; it is not auto-discovered.

The `nvl:schema:*` commands ship through the 6.x line only. Family 7 does not register them, so complete this step before upgrading to 7.

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=translations --claim-legacy --migration-owner=vendor --dry-run --format=json
php artisan nvl:schema:upgrade --package=translations --claim-legacy --migration-owner=vendor --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Declare each published path and canonical identity explicitly in `nvl-core.migrations.published`; retimestamped history also needs an exact `legacy` mapping. Use `--migration-owner=vendor` after manually archiving declared copies outside loaded paths, or `--migration-owner=published` after manually replacing executable copies with current migration code and disabling vendor loading. The plan verifies ownership and preserves batches; checksums do not automatically claim files. Modified host copies remain host-owned. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, run `nvl:schema:preflight` with the same selected paths and connection, then migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.
