# Upgrading NVL Translations

## Consumer contracts, committed events and runtime policy (5.x)

Prefer focused public interfaces in constructor injection; native implementations remain container defaults and host prebindings win. Returned models are documented identity/data handles: use package contracts for reads/writes and capability-specific batch readers instead of direct package queries. Enable the shipped Core PHPStan include in your host; do not invoke the suite workbench static audit command in a consumer.

Events now carry immutable schemaVersion=1 and scalar/DTO snapshots. Replace model-bearing event fields with the IDs listed in [events](docs/events.md); load only through an authorized public reader when needed. Only six declared legacy `*Event` names are retained as PHP aliases for major 5, removal no earlier than major 6. Migrate exact imports/listeners/fakes to canonical names, replace suffix wildcard patterns explicitly, drain old queued payloads, rebuild event caches and restart workers. Framework Verified/PasswordReset remain native classes. Source-connection callbacks are process-local after-commit publication, not a durable outbox or exactly-once delivery.

Package failures have a marker and optional response metadata. Opt into Core's JSON renderer deliberately; preserve existing host handlers and request-locale selection. Missing required host adapters produce `binding_required`/500; genuine configured authorization denial retains native handling. See the README error table and required-bindings section where applicable.

Factories ship in runtime package mappings for host tests. Ordinary make may persist parents; withoutParents()->make creates detached fixtures. Supply persisted native owners/parents and active tenants explicitly, retain source revisions, and never treat a factory row as a real storage/provider/workflow effect. Core's optional installer publishes common config without enabling features; strict Doctor and explicit deployment cache/worker steps belong in the host release process. The PHP 8.4/Laravel 13 local Dagger release gate and fresh public Composer installation passed for 5.0.0. Additional compatibility legs need separate evidence; hosts must verify their own adoption.


## Tenant adoption

Adopt source catalogs as fixed platform resources and install the separate
tenant override schema. Configure a literal key allowlist; source paths are
never valid override keys. Existing source rows are not assigned to a tenant.

## Upgrading to 1.0

Version 1.0 treats files as authoritative and the database as an editable workspace.

1. Install every package migration, including unique `identity_hash` columns, durable scan runs, usage `scan_id` linkage, and catalog query indexes.
2. Remove `mail` from configured scope selections; mail catalogs are application PHP groups.
3. Define explicit source, module-root, custom, and named target profiles.
4. Configure a shared lock-capable cache store for multi-node deployments.
5. Run `php artisan nvl:translations:doctor --strict --format=json`.
6. Run scan, `sync --dry-run`, sync, and status.
7. Resolve every source-hash conflict explicitly.
8. Run `export --dry-run`, enable backups, then export.
9. Run `prune --dry-run` before destructive cleanup.

PHP array-key segments containing literal dots or empty strings must be renamed before synchronization; dots are reserved for nested key paths. Exports now stop whenever the pre-export authoritative read reports an incomplete source.

Run a fresh scan after migrating. Usage identities now include the resolved scope, and the new scan-run linkage makes that scan the authoritative baseline while historical usage rows age out according to retention.

Never assume a database edit may silently overwrite a changed file.

## Shared Doctor integration

The loaded package provider now contributes its existing inspection checks to Core's `nvl:doctor --strict --format=json`. The package command remains available. The shared gate fails errors and, in strict mode, warnings; no data upgrade is required for diagnostics.


## Infrastructure option inheritance

Move `lock.store` to `locks.store`, or set the canonical store to null to inherit Core and Laravel. Keep workspace `lock.seconds` and `lock.wait_seconds`; compatibility store inputs remain supported for one major cycle and are reported by Doctor. Rebuild cached configuration after changing stores.

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

## Tagged consumer PHP boundary

Use source `@api` workflows, extension contracts, and value types for application integration. Direct use of untagged implementations or `@internal` members is unsupported. This classification keeps existing concrete Action signatures and runtime behavior; it does not authorize package model persistence, ad hoc queries, relation traversal, or generic model serialization. Returned models are identity/result handles with only the explicitly declared in-memory read fields described in the README.

## Application workflow contracts

The existing ImportTranslationsContract, ScanTranslationsContract, and UpdateTranslationEntryContract are reused. Six additional selected workflows gain focused contracts without changing scanner/export/import behavior.

The supported workflow injection names are `GetTranslationCatalogStatisticsContract`, `ListTranslationEntriesContract`, `ListTranslationFilterOptionsContract`, `UpdateTranslationEntryContract`, `ExportTenantTranslationsContract`, `ExportTranslationsContract`, `ImportTranslationsContract`, `ListUnusedTranslationsContract`, `ScanTranslationsContract`.

Inject these contracts when application workflows need substitution. Native
concrete constructors and operation signatures remain available through major 5;
internal workflow chains are unchanged. Register host implementations before
package discovery or replace the contract before resolving a new host service.
See [Testing your app](README.md#testing-your-app) for native fixtures and the
shipped consumer-audit PHPStan configuration.
