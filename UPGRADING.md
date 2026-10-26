# Upgrading NVL Translations to Light

Upgrade the selected dependency graph together on Laravel 13 and Testbench 11. The intermediate package family remains 6 until the coordinated family version change.

Replace Action/DTO contracts with `TranslationImporter::import`, `TranslationExporter::export`, `TranslationScanner::scan`, and `TranslationCatalog::{paginate,update,statistics,unused}`. Inputs use native scalars and documented arrays; update requires the `value` key (nullable) and a positive `expectedRevision`. Pass an explicit persisted `Authenticatable` or `SystemActor` and bind the host authorization policy. A system identity grants no permission by itself.

Source roots and export destinations remain configured and path-guarded. Export returns counts plus a list of artifact paths. Keep application-owned HTTP validation, responses, editor/select projections and type generation in the host. Package routes, DTOs, Action interfaces, frontend metadata and legacy Tenancy integration are removed.

Owner copy continues through `TranslationOverrideRepository` inside native Ownership execution. Explicit native adoption preserves populated override rows, IDs, values, revisions and timestamps. It no longer discovers or converts an old tenant table automatically; plan any historical data migration explicitly in the host. Keep migration history and reviewed provenance.

`TranslationEntryUpdated::entry` is now a detached array snapshot. Drain old queued payloads and restart workers when updating listeners. Events publish after the actual source connection commits; filesystem completion and SQL transaction completion remain separate facts.

See [README](README.md) for native signatures and retained source, scan, backup and lock configuration.

## Next major: isolated schema identities

This is a breaking schema identity change. Back up storage and migration history, pause writes/workers, install this code with automatic package migrations disabled, and select one owner for migrations (vendor or published).

```sh
php artisan nvl:doctor --strict --format=json
php artisan nvl:schema:upgrade --package=translations --claim-legacy --migration-owner=vendor --dry-run --format=json
php artisan nvl:schema:upgrade --package=translations --claim-legacy --migration-owner=vendor --format=json
```

The command validates released columns and relational keys plus creating migration history, renames owned legacy tables to the effective `tables.*` targets and rewrites exact package migration identities while retaining batches and unrelated host records. It refuses foreign/incomplete shapes and conflicting targets. Explicit old table mappings retain those names; remove them when choosing new defaults. A second run is empty.

Declare each published path and canonical identity explicitly in `nvl-core.migrations.published`; retimestamped history also needs an exact `legacy` mapping. Use `--migration-owner=vendor` after manually archiving declared copies outside loaded paths, or `--migration-owner=published` after manually replacing executable copies with current migration code and disabling vendor loading. The plan verifies ownership and preserves batches; checksums do not automatically claim files. Modified host copies remain host-owned. No migration files or stored morph types are rewritten.

DDL transactions are driver dependent and per connection. Inspect dry-run warnings for MySQL/MariaDB or split storage; after a failure, inspect completed steps before resuming. Schema-qualified rename targets require an explicit host schema move first. Re-enable your selected migration owner, run `nvl:schema:preflight` with the same selected paths and connection, then migrate remaining package changes and rerun Doctor before resuming writes. See the suite upgrade guide for shared owner/locale inputs, Core option defaults and one-major deprecation rules.
