---
name: nvl-translations
description: Use native translation catalog, scanning, import and export services in Laravel 13.
---

# NVL Translations

Inject `TranslationCatalog`, `TranslationScanner`, `TranslationImporter` and `TranslationExporter` for complete operations. Pass an explicit authenticated principal or `SystemActor::Background`; the configured authorization adapter decides access for either actor.

Consumers own DTOs, Actions, controllers, routes, resources and frontend types. Map application inputs into the documented native scalar and array service contracts. Catalog reads return Eloquent paginators; updates return `TranslationEntry` models.

Keep revision checks, closed mutation fields, scope authorization, locks, path containment, dry runs and atomic file publication inside the service. Preserve omission versus explicit null when updating values. Keep PHP language files and JSON translation keys separate and retain the documented conflict policy during import/export.

Use the packaged console commands for host scheduling and CLI workflows. See the package README for supported scopes, locales, formats and source/target configuration. Package installation preserves unowned operation; select native Ownership explicitly when isolation is required. No deprecated Tenancy bridge is shipped.
