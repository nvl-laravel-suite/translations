# Upgrading NVL Translations

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
