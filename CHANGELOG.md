# Changelog


All notable changes to `nvl/translations` are documented here.

## [Unreleased]

### Fixed

- `nvl:adopt translations` verify and activate take the workspace process lock before the run row, the order apply uses, through Core's `AdoptionAdapter::aroundVerification()` hook; they no longer wait on the lock while holding the run row.
- Resuming `nvl:adopt translations` re-reads the committed source prefix of every stored checkpoint, completed ones included, and refuses the run when a staged entry or usage behind the cursor was deleted or changed.
- A workspace operation called inside a caller's open transaction keeps the workspace lock until that transaction's outermost commit or rollback on the catalog connection, so its row locks are never left behind without the lock; a transaction on another connection never releases it. Later operations in that transaction reuse the lock only while the lock store still names this process its owner, so after a failed COMMIT the next transaction acquires a fresh lock.
- Import, scan and export reject a symbolic link anywhere below a language, scan or export root instead of silently skipping it, which marked entries missing and skipped stale-file pruning.
- The scanner keeps keys exactly as written, including surrounding whitespace and literal `$`; it skips only double-quoted PHP strings that interpolate and template literals that contain `${`.
- Catalog edits, missing markers and the completed scan marker require a successful save; a vetoed save throws `TranslationsException`, rolls back, and fires no `TranslationEntryUpdated`.

### Changed

- Requires `nvl/core` 7.3.0 for the adoption verification hook and checkpoint confirmation.

## [7.2.0] - 2026-11-16

### Added

- Adoption runs through nvl:adopt with reviewed, checkpointed batches and an explicit activate step. Committed batches survive a failure. Keep writers stopped until verify and activate pass. The `translations` adapter stages entries, then usages, holds the workspace process lock for each batch and keeps the reviewed `source_values` baselines; `TranslationCatalogAdopter` keeps its existing behaviour.

### Fixed

- Search supports host text matchers that return a new builder.

<!-- NVL GENERATED CHANGES START -->
### Changed

- adoption flow, PostgreSQL proof, release health and 7.2 floors
<!-- NVL GENERATED CHANGES END -->

## [7.1.0] - 2026-11-16

### Highlights

- Adopt a legacy file catalog and select translation imports and exports by scope, format, and group prefix.

<!-- NVL GENERATED CHANGES START -->
### Changed

- close NVL consumer adoption and portability gaps
- require Core 7.1 for packages using the text matcher
- Merge pull request #21 from nicolasvlachos/codex/consumer-postgresql-validation
<!-- NVL GENERATED CHANGES END -->

## [7.0.4] - 2026-11-13

### Fixed

- Match Unicode catalog searches case-insensitively on PostgreSQL `C`-locale clusters via `COLLATE pg_c_utf8`, invalidate OPcache after PHP artifact writes and rollbacks, and harden export and Doctor checks.

<!-- NVL GENERATED CHANGES START -->
### Changed

- harden package runtime safety, PostgreSQL portability and test performance
<!-- NVL GENERATED CHANGES END -->

## [7.0.3] - 2026-11-11

### Fixed

- Imports invalidate each PHP catalog in OPcache before reading it. A synchronization that followed a source export in the same PHP-FPM pool could read the pre-export compiled file and revert the exported edit.
- `TranslationCatalog::update` reports a malformed entry identifier as not found instead of raising a PostgreSQL `22P02` query error.
- The `key_contains` catalog criterion ignores letter case on PostgreSQL as it already did on SQLite.
- The doctor's `lock.workspace` check inspects the store that workspace locking uses, including one inherited from `nvl-core.locks.store`.
- The default scanner patterns capture keys that contain an escaped quote or the other quote character, and a key with empty dot segments such as `Loading...` is recorded as JSON usage. Such keys were reported as unused.
- Imports and source exports no longer bump `revision` and `updated_at` on entries they leave unchanged, so an editor's expected revision survives an unrelated synchronization or export.
- Exports leave an artifact that already holds its exported content in place instead of replacing it and adding another backup copy on every run.
- Application-scope exports and prunes reject `vendor` as a locale; pruning it deleted the nested vendor-scope catalogs.
- The published configuration declares the documented `connection` key.
- `nvl:translations:unused` says when no completed scan backs the report.

<!-- NVL GENERATED CHANGES START -->
### Changed

- correct workspace import and export regressions
<!-- NVL GENERATED CHANGES END -->

## [7.0.2] - 2026-11-10

### Changed

- Remove the obsolete Spatie Laravel Data development dependency. Translation test adapters now use native validation and explicit projections, including omitted values and null clearing.

<!-- NVL GENERATED CHANGES START -->
### Changed

- retire Spatie Data test dependency
<!-- NVL GENERATED CHANGES END -->

## [7.0.1] - 2026-11-09

### Changed

- Refresh the inherited Ownership deletion API reference for ordinary models and native pivots.

<!-- NVL GENERATED CHANGES START -->
### Changed

- refresh inherited ownership deletion contracts
<!-- NVL GENERATED CHANGES END -->

## [7.0.0] - 2026-11-03

- Adopt the reviewed NVL 7 family for PHP 8.4+ and Laravel 13, with compatible required and optional NVL peers. Review the package upgrade guide before deployment.

### Changed

- Align NVL peers and consumer contracts on the coordinated 7.x line. Review [UPGRADING.md](UPGRADING.md) for adoption and application-owned integration requirements.

## [6.0.0] - 2026-10-20

### Release fixes

- Include the existing native ownership default in published common configuration.

### Changed

- Add native owner-local translation overrides, exact keys and revisions, reviewed copy adoption and owner-scoped reads/exports. Retain old overrides as provenance; source scanning and file mutation stay outside owner execution.
- Align required/development NVL peers on `^6.0`, optional peer conflicts on major 6, and `dev-main` on `6.x-dev`. Review [UPGRADING.md](UPGRADING.md) before rollout.

## [5.0.1] - 2026-10-08

### Changed

- Correct published-family verification and adoption guidance for local Dagger CI; runtime contracts are unchanged.


## [5.0.0] - 2026-10-08

### Changed

- The existing ImportTranslationsContract, ScanTranslationsContract, and UpdateTranslationEntryContract are reused. Six additional selected workflows gain focused contracts without changing scanner/export/import behavior. Document contract substitution and truthful host fixtures in Testing your app.
- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Adopt lockstep major 5 with required and development NVL peer floors of `^5.0`.
- Namespace configuration, env and translation registrations while retaining existing translation workflows.
- Review [UPGRADING.md](UPGRADING.md) before adopting the new names and infrastructure boundaries.

## [2.2.1] - 2026-09-26

### Documentation

- Clarify public support, contribution, and private security reporting paths.

## [2.2.0] - 2026-09-25

### Changed

- Prepare `nvl/translations` for independent Composer and Git publication; require `nvl/core` for shared Support and Data services.

## [2.1.1] - 2026-09-23

### Changed

- Replace catalog, import, and export Form Requests with Data contracts while
  preserving list normalization, export confirmation, prune authorization,
  supported pagination aliases, and existing response envelopes.

## [2.1.0] - 2026-09-23

### Fixed

- Preserve host-provided tenant repository and translation update, import, and
  scan action bindings when the Translations service provider registers.

## [2.0.1] - 2026-09-22

### Added

- Added platform-only source tooling, tenant copy overrides, isolated private
  export artifacts, adoption, and tenancy diagnostics.

### Fixed

- Preserve top-level numeric PHP translation keys during import and exact file round trips instead of failing the typed key boundary.

## [2.0.0] - 2026-08-29

### Changed

- Established scan, import, export, update, statistics, and shared-filter
  Actions/services as the 2.0 consumer boundary; direct consumer translation
  catalog-model queries now fail Suite audit.

## [1.0.7] - 2026-08-22

### Changed

- Aligned the documented runtime requirement with the PHP 8.4+ package
  baseline.

## [1.0.5] - 2026-08-12

### Changed

- Released unchanged under the suite's shared version.

## [1.0.2] - 2026-08-12

- Excluded development-only tests and static-analysis configuration from
  release archives and removed obsolete empty package directories.
- Added consumer-contract coverage for every workspace command, strict
  diagnostics, authorization, public validation, filters, and conflict
  responses.
- Corrected repeated missing-entry reporting and rejected null-leaf/nested PHP key collisions during export.
- Added durable scan-run records and scope-aware usage identities so zero-hit, same-second, and same-line multi-scope scans produce correct unused reports.
- Kept PHP and JSON usage identities separate and excluded already-missing source rows from unused reports.
- Rejected ambiguous PHP dot-key segments, catalog output leakage, scanner/import symlink escapes, ambiguous namespaces, unsafe artifact plans, and case-insensitive destination collisions.
- Blocked exports after incomplete authoritative reads and aligned `changed_since_import` with durable synchronization state.
- Tightened API list shapes, required update values, command option validation, doctor checks, configuration typing, path normalization, and catalog query indexes.

## [1.0.0] - 2026-08-08

- Added authoritative PHP and JSON language-file scanning and database workspace synchronization.
- Added case-sensitive SHA-256 catalog identities with unrestricted long keys and exact UTF-8 string/null value fidelity.
- Added configurable source and distinct named target profiles, source hashes, conflicts, typed status, shared locks, staged batch export, rollback, backups, and pruning.
- Added path, symlink, encoding, malformed-file, duplicate/overlapping-scope, target-overlap, and vendor-write protections.
- Added scoped literal-key scanning, configurable patterns, retained usage history, and unused-key reporting.
- Added disabled-by-default authorized workspace APIs with optimistic revisions, independent prune authorization, force requirements, and stable error responses.
- Added Laravel 12–13, PHP 8.3–8.4, SQLite and PostgreSQL quality coverage and the documented migrations and skill publish tags.
