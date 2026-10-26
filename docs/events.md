# Translation events

All events implement `Nvl\Support\Contracts\DomainEvent` with schema version 1. Publication uses the actual translation storage connection and waits for its outer commit. Callbacks are process-local; this is not a durable outbox.

| Event | Payload | Producer |
| --- | --- | --- |
| `TranslationEntryUpdated` | Detached `entry` array with text, revision, state and timestamps | `TranslationCatalog::update` |
| `TranslationsImported` | Counts for scopes/files/entries/created/updated/preserved/conflicts/missing and warnings | `TranslationImporter::import` |
| `TranslationsExported` | Scopes/locales/files/deleted counts, named target and actual artifact paths | `TranslationExporter::export` |
| `TranslationsScanned` | File and hit counts, immutable `scanned_at` timestamp | `TranslationScanner::scan` |

Protect event payloads as application data. Entry snapshots include translation text and conflict metadata. Dry-run import and export do not publish completed-operation events. File replacement retains its own staging/backup/rollback guarantees; an outer database rollback cannot undo an already completed filesystem batch.

The machine-readable field shapes are in [event-catalog.json](../resources/event-catalog.json).
