# NVL translations events

This document describes the implemented source behavior. Executable acceptance proof is pending the final testing phase. The authoritative machine-readable schema is [event-catalog.json](../resources/event-catalog.json), catalog version `1`. Event `schemaVersion` is independent of catalog version.

## Publication and listener timing

The native host dispatcher receives the captured event after the supplied source connection outer commit, or immediately when that connection has no active transaction.

Callbacks attach to the matching native connection and current nesting record; native outer/savepoint rollback discards the corresponding callbacks.

Missing source transaction records fail before commit; Mail Notifications reports and drops unusable observations.

Host after-commit listeners and queue after_commit policies can add their own deferral after publication. Host transaction infrastructure and dispatcher bindings are preserved.

Local callbacks are not an outbox. Process exit between commit and callback can lose delivery; no crash durability or exactly-once delivery is promised.

One canonical object is dispatched per qualifying producer call. This is local publication, not cross-process deduplication or a guarantee that repeated observations are unique.

Use Nvl\Support\Events\DomainEventDispatcher::dispatch($event, $writerConnection). Native Event::dispatch() is immediate and has no package interception.

## Payload security and no-op behavior

TranslationEntryUpdated carries TranslationEntryPayload including text value and conflictMetadata; trust this workspace integration and protect its data. Import/export/scan summaries include counts, warnings, target string and immutable scan timestamp, never whole source files/models. PHPDoc shapes document arrays; they are not constructor-time recursive validators.

Committed manual edits and completed file/import/export/scan operations are separate facts. Filesystem writes are not rolled back by SQL transaction rollback; event timing is scoped to the supplied translation storage connection.

Native array result shapes include CarbonImmutable in TranslationsScanned.result.scanned_at. TranslationEntryPayload exposes enum scope/format/syncStatus, nullable text/conflict data and immutable import/export/create/update timestamps.

Actor/owner identifiers do not grant access. Listeners must preserve the captured ownership and apply their own authorization when reading storage. Readonly payload fields and native value objects are schema facts; public constructors with mixed arrays do not create a new recursive sanitization boundary. Package producer shapes are documented below; hosts must not attach models, mutable service objects or private arbitrary data.

## Canonical events

| Event | Schema version | Trigger |
| --- | --- | --- |
| [TranslationEntryUpdated](#translationentryupdated) | 1 | Manual workspace entry persisted. |
| [TranslationsExported](#translationsexported) | 1 | Database-to-file synchronization completed. |
| [TranslationsImported](#translationsimported) | 1 | Authoritative-file import completed. |
| [TranslationsScanned](#translationsscanned) | 1 | Source usage scan completed. |

### TranslationEntryUpdated

`Nvl\Translations\Events\TranslationEntryUpdated` · [source](../src/Events/TranslationEntryUpdated.php) · event schema `1`.

Manual workspace entry persisted.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$entry` | `Nvl\Translations\Data\TranslationEntryPayload` | public | `required` | — |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$entry` | `Nvl\Translations\Data\TranslationEntryPayload` | — |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/Entries/UpdateTranslationEntryAction.php](../src/Actions/Entries/UpdateTranslationEntryAction.php) | `$model->getConnection()` |

### TranslationsExported

`Nvl\Translations\Events\TranslationsExported` · [source](../src/Events/TranslationsExported.php) · event schema `1`.

Database-to-file synchronization completed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$result` | `array` | public | `required` | `array{scopes:int,locales:int,files:int,deleted:int,target:string}` |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$result` | `array` | `array{scopes:int,locales:int,files:int,deleted:int,target:string}` |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/Sync/ExportTranslationsAction.php](../src/Actions/Sync/ExportTranslationsAction.php) | `DB::connection(PackageStorage::connection('translations'))` |

### TranslationsImported

`Nvl\Translations\Events\TranslationsImported` · [source](../src/Events/TranslationsImported.php) · event schema `1`.

Authoritative-file import completed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$result` | `array` | public | `required` | `array{scopes:int,files:int,entries:int,created:int,updated:int,preserved:int,conflicts:int,missing:int,warnings:list<string>}` |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$result` | `array` | `array{scopes:int,files:int,entries:int,created:int,updated:int,preserved:int,conflicts:int,missing:int,warnings:list<string>}` |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/Sync/ImportTranslationsAction.php](../src/Actions/Sync/ImportTranslationsAction.php) | `DB::connection(PackageStorage::connection('translations'))` |

### TranslationsScanned

`Nvl\Translations\Events\TranslationsScanned` · [source](../src/Events/TranslationsScanned.php) · event schema `1`.

Source usage scan completed.

Constructor parameters, in native order:

| Parameter | Native PHP type | Visibility | Default | Collection shape |
| --- | --- | --- | --- | --- |
| `$result` | `array` | public | `required` | `array{files:int,hits:int,scanned_at:Carbon\CarbonImmutable}` |
| `$schemaVersion` | `int` | public | `1` | — |

Public payload fields:

| Field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$result` | `array` | `array{files:int,hits:int,scanned_at:Carbon\CarbonImmutable}` |
| `$schemaVersion` | `int` | — |

Producers and exact scheduling connection expressions (variables are resolved in the linked source):

| Producer | Source connection |
| --- | --- |
| [Actions/Sync/ScanTranslationsAction.php](../src/Actions/Sync/ScanTranslationsAction.php) | `DB::connection(PackageStorage::connection('translations'))` |

## Referenced payload types

Native event field types are listed above; nested declared fields and backed enum values follow. Private captured envelopes are included because serialized/queued objects retain them. Dates use `Carbon\CarbonImmutable`. Spatie Data serialization can also carry its protected transformation metadata; recursive graph acceptance checks remain pending.

### TranslationEntryPayload

`Nvl\Translations\Data\TranslationEntryPayload` · [source](../src/Data/TranslationEntryPayload.php).

| Declared public field | Native PHP type | Collection shape |
| --- | --- | --- |
| `$id` | `string` | `string` |
| `$scopeType` | `Nvl\Translations\Enums\TranslationScopeType` | `Nvl\Translations\Enums\TranslationScopeType` |
| `$scopeName` | `string` | `string` |
| `$locale` | `string` | `string` |
| `$format` | `Nvl\Translations\Enums\TranslationFormat` | `Nvl\Translations\Enums\TranslationFormat` |
| `$group` | `?string` | `string\|null` |
| `$key` | `string` | `string` |
| `$value` | `?string` | `string\|null` |
| `$isMissing` | `bool` | `bool` |
| `$revision` | `int` | — |
| `$syncStatus` | `Nvl\Translations\Enums\TranslationSyncStatus` | — |
| `$conflictMetadata` | `?array` | `array<string, mixed>\|null` |
| `$lastImportedAt` | `?Carbon\CarbonImmutable` | `Carbon\CarbonImmutable\|null` |
| `$lastExportedAt` | `?Carbon\CarbonImmutable` | — |
| `$createdAt` | `Carbon\CarbonImmutable` | `Carbon\CarbonImmutable` |
| `$updatedAt` | `Carbon\CarbonImmutable` | `Carbon\CarbonImmutable` |

### TranslationFormat

`Nvl\Translations\Enums\TranslationFormat` · [source](../src/Enums/TranslationFormat.php).

Backed string values: `Php = php`, `Json = json`.

### TranslationScopeType

`Nvl\Translations\Enums\TranslationScopeType` · [source](../src/Enums/TranslationScopeType.php).

Backed string values: `App = app`, `Module = module`, `Vendor = vendor`, `Custom = custom`.

### TranslationSyncStatus

`Nvl\Translations\Enums\TranslationSyncStatus` · [source](../src/Enums/TranslationSyncStatus.php).

Backed string values: `Synchronized = synchronized`, `Edited = edited`, `Conflict = conflict`, `Missing = missing`.

## Deferred acceptance checks

Final testing must compare catalog types/defaults/aliases with actual classes, recursively inspect producer payloads, and prove source outer commit, nested rollback, unrelated connection independence and retry behavior without an uncommitted test-harness transaction. Where applicable it must cover legacy exact/cached/queued listeners, canonical fakes and wildcard delivery, tenant capture, package no-op guards and observational failure containment. This document does not report those checks as passing.
