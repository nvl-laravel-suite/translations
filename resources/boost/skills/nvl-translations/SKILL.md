---
name: nvl-translations
description: Use when scanning Laravel string usage, editing a revisioned translation catalog, synchronizing PHP/JSON language files, exporting artifacts or managing native owner copy overrides.
---

# NVL Translations

Translations manages Laravel PHP/JSON string catalogs and source usage.
Use `nvl/translatable` for localized Eloquent content fields.
The host owns HTTP editors, routes, DTOs, frontend types and permission policy.

## References

- [Installation](references/installation.md): migrations, providers, publication and configuration.
- [API](references/api.md): service signatures, criteria, results, policy and model contracts.
- [Usage](references/usage.md): trusted scopes/targets, import/edit/export/scan and owner overrides.

Read the source-workspace or owner-override recipe before choosing authority/context.

## Choose the capability

| Need | Native service |
| --- | --- |
| Read/edit workspace values | `TranslationCatalog` |
| Import selected language files | `TranslationImporter` |
| Publish validated artifacts | `TranslationExporter` |
| Record literal source usage | `TranslationScanner` |
| Per-owner literal copy | `TranslationOverrideRepository` |
| Deployment diagnostics | `TranslationsDoctor` and native commands |

## Source workspace policy

Pass an explicit persisted Eloquent `Authenticatable` or deliberately authorized `SystemActor`.
Bind `TranslationsAuthorization`, or configure the human Gate ability; default access is denied.
Canonical writer storage reloads actors before policy; dirty attributes/loaded relations cannot grant access.
CLI background actors require the same explicit host policy binding.
Workspace operations are unavailable inside owner/public-site execution.
Source scopes are configured tokens: `app`, `module:name`, `vendor:package`, `custom:name`.
Paths and named export targets are trusted host configuration, never arbitrary request paths.
Keep PHP groups and JSON full-string identities separate.
Manage only valid UTF-8 string/null leaves; numeric/boolean leaves reject.

## Closed writes and publication

Catalog updates accept exactly `value` and positive integer `expected_revision`.
Explicit null clears the value; omitting value is invalid.
Stale revisions fail rather than overwrite another editor.
Imports read selected sources before mutation and enforce configured conflict behavior.
Export first synchronizes authoritative sources, stages all artifacts and publishes a backed-up atomic replacement batch.
An incomplete source read blocks export; symlink/path escapes reject.
Non-dry-run export must start outside a transaction on the actual catalog connection.
Dry runs publish no files and restore their own simulated database changes.
File rollback is best effort after failure; database rollback cannot undo a previously published filesystem result.

## Owner copy and model choice

Owner overrides require optional native Ownership, admitted owner context and literal configured `ownership.keys`.
Use revision zero to create; positive exact revisions update/delete on the actual model connection.
Configure `models.override` before provider boot for a compatible `TranslationOverride` subclass.
Preserve native ownership, identity, casts, lifecycle hooks and unique owner/key/locale schema.
Owner exports are private and return identity, disk/path, hash and size.
Unowned source operations remain usable with Ownership physically absent.
Do not use the retired Tenancy bridge or source workspace calls to edit owner copy.

## Bounds, operations and verification

Catalog pagination clamps to at most 200 rows; filters/sorts remain allowlisted.
Scanner paths/extensions/patterns and retention are host configuration; dynamic keys need an explicit allowlist.
Use a shared atomic lock store for concurrent workspace workers and inspect actual lock settings in installation.
Run native `sync`, `export --dry-run`, `scan`, `status`, `unused` and `doctor` commands as relevant.
Pruning is explicit and constrained to selected scopes/locales/formats/target.
Test conflicts, revisions, nulls, containment, rollback/recovery, current actor admission and private overrides.
Run affected host tests; no package HTTP transport or TypeScript generation is implied.
