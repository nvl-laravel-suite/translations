# Public API: nvl/translations

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\Translations\Contracts\TranslationOverrideRepository`
- `Nvl\Translations\Contracts\TranslationsAuthorization`
- `Nvl\Translations\Database\Factories\TranslationEntryFactory`
- `Nvl\Translations\Database\Factories\TranslationScanRunFactory`
- `Nvl\Translations\Database\Factories\TranslationUsageFactory`
- `Nvl\Translations\Enums\TranslationFormat`
- `Nvl\Translations\Enums\TranslationScopeType`
- `Nvl\Translations\Enums\TranslationSyncStatus`
- `Nvl\Translations\Enums\TranslationsAbility`
- `Nvl\Translations\Enums\TranslationsResponseCode`
- `Nvl\Translations\Events\TranslationEntryUpdated`
- `Nvl\Translations\Events\TranslationsExported`
- `Nvl\Translations\Events\TranslationsImported`
- `Nvl\Translations\Events\TranslationsScanned`
- `Nvl\Translations\Exceptions\InvalidTranslationInputException`
- `Nvl\Translations\Exceptions\StaleTranslationWorkspaceException`
- `Nvl\Translations\Exceptions\TranslationConflictException`
- `Nvl\Translations\Exceptions\TranslationWorkspaceLockedException`
- `Nvl\Translations\Exceptions\TranslationsException`
- `Nvl\Translations\Models\TranslationEntry`
- `Nvl\Translations\Models\TranslationOverride`
- `Nvl\Translations\Models\TranslationScanRun`
- `Nvl\Translations\Models\TranslationUsage`
- `Nvl\Translations\Services\TranslationCatalog`
- `Nvl\Translations\Services\TranslationExporter`
- `Nvl\Translations\Services\TranslationImporter`
- `Nvl\Translations\Services\TranslationScanner`

## Nvl\Translations\Contracts\TranslationOverrideRepository

```text
interface Nvl\Translations\Contracts\TranslationOverrideRepository
public abstract function export(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $disk): array
@return array{owner: array<string, mixed>, disk: string, path: string, sha256: string, size: int}
public abstract function forget(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $key, string $locale, int $expectedRevision): void
public abstract function get(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $key, string $locale): ?string
public abstract function set(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $key, string $locale, string $value, int $expectedRevision): void
```

## Nvl\Translations\Contracts\TranslationsAuthorization

```text
interface Nvl\Translations\Contracts\TranslationsAuthorization
public abstract function authorize(Nvl\Translations\Enums\TranslationsAbility $ability, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, ?Nvl\Translations\Models\TranslationEntry $entry=null): void
```

## Nvl\Translations\Database\Factories\TranslationEntryFactory

```text
final class Nvl\Translations\Database\Factories\TranslationEntryFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<TranslationEntry>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Translations\Models\TranslationEntry>
public function definition(): array
@return array<model-property<TranslationEntry>, mixed>
resolved @return array<model-property<Nvl\Translations\Models\TranslationEntry>, mixed>
```

## Nvl\Translations\Database\Factories\TranslationScanRunFactory

```text
final class Nvl\Translations\Database\Factories\TranslationScanRunFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<TranslationScanRun>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Translations\Models\TranslationScanRun>
public function definition(): array
@return array<model-property<TranslationScanRun>, mixed>
resolved @return array<model-property<Nvl\Translations\Models\TranslationScanRun>, mixed>
```

## Nvl\Translations\Database\Factories\TranslationUsageFactory

```text
final class Nvl\Translations\Database\Factories\TranslationUsageFactory extends Illuminate\Database\Eloquent\Factories\Factory
@extends Factory<TranslationUsage>
resolved @extends Illuminate\Database\Eloquent\Factories\Factory<Nvl\Translations\Models\TranslationUsage>
public function definition(): array
@return array<model-property<TranslationUsage>, mixed>
resolved @return array<model-property<Nvl\Translations\Models\TranslationUsage>, mixed>
public function forScan(Nvl\Translations\Models\TranslationScanRun $parent): static
```

## Nvl\Translations\Enums\TranslationFormat

```text
enum Nvl\Translations\Enums\TranslationFormat implements BackedEnum, UnitEnum: string
public const Nvl\Translations\Enums\TranslationFormat Json=Nvl\Translations\Enums\TranslationFormat::Json="json"
public const Nvl\Translations\Enums\TranslationFormat Php=Nvl\Translations\Enums\TranslationFormat::Php="php"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Translations\Enums\TranslationScopeType

```text
enum Nvl\Translations\Enums\TranslationScopeType implements BackedEnum, UnitEnum: string
public const Nvl\Translations\Enums\TranslationScopeType App=Nvl\Translations\Enums\TranslationScopeType::App="app"
public const Nvl\Translations\Enums\TranslationScopeType Custom=Nvl\Translations\Enums\TranslationScopeType::Custom="custom"
public const Nvl\Translations\Enums\TranslationScopeType Module=Nvl\Translations\Enums\TranslationScopeType::Module="module"
public const Nvl\Translations\Enums\TranslationScopeType Vendor=Nvl\Translations\Enums\TranslationScopeType::Vendor="vendor"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Translations\Enums\TranslationSyncStatus

```text
enum Nvl\Translations\Enums\TranslationSyncStatus implements BackedEnum, UnitEnum: string
public const Nvl\Translations\Enums\TranslationSyncStatus Conflict=Nvl\Translations\Enums\TranslationSyncStatus::Conflict="conflict"
public const Nvl\Translations\Enums\TranslationSyncStatus Edited=Nvl\Translations\Enums\TranslationSyncStatus::Edited="edited"
public const Nvl\Translations\Enums\TranslationSyncStatus Missing=Nvl\Translations\Enums\TranslationSyncStatus::Missing="missing"
public const Nvl\Translations\Enums\TranslationSyncStatus Synchronized=Nvl\Translations\Enums\TranslationSyncStatus::Synchronized="synchronized"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Translations\Enums\TranslationsAbility

```text
enum Nvl\Translations\Enums\TranslationsAbility implements BackedEnum, UnitEnum: string
public const Nvl\Translations\Enums\TranslationsAbility Export=Nvl\Translations\Enums\TranslationsAbility::Export="export"
public const Nvl\Translations\Enums\TranslationsAbility ListEntries=Nvl\Translations\Enums\TranslationsAbility::ListEntries="list_entries"
public const Nvl\Translations\Enums\TranslationsAbility Prune=Nvl\Translations\Enums\TranslationsAbility::Prune="prune"
public const Nvl\Translations\Enums\TranslationsAbility Scan=Nvl\Translations\Enums\TranslationsAbility::Scan="scan"
public const Nvl\Translations\Enums\TranslationsAbility Synchronize=Nvl\Translations\Enums\TranslationsAbility::Synchronize="synchronize"
public const Nvl\Translations\Enums\TranslationsAbility UpdateEntry=Nvl\Translations\Enums\TranslationsAbility::UpdateEntry="update_entry"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Translations\Enums\TranslationsResponseCode

```text
enum Nvl\Translations\Enums\TranslationsResponseCode implements BackedEnum, Nvl\Support\Contracts\ResponseCode, UnitEnum: string
public const Nvl\Translations\Enums\TranslationsResponseCode Exported=Nvl\Translations\Enums\TranslationsResponseCode::Exported="exported"
public const Nvl\Translations\Enums\TranslationsResponseCode Imported=Nvl\Translations\Enums\TranslationsResponseCode::Imported="imported"
public const Nvl\Translations\Enums\TranslationsResponseCode InvalidTranslationInput=Nvl\Translations\Enums\TranslationsResponseCode::InvalidTranslationInput="invalid_translation_input"
public const Nvl\Translations\Enums\TranslationsResponseCode OperationFailed=Nvl\Translations\Enums\TranslationsResponseCode::OperationFailed="operation_failed"
public const Nvl\Translations\Enums\TranslationsResponseCode Scanned=Nvl\Translations\Enums\TranslationsResponseCode::Scanned="scanned"
public const Nvl\Translations\Enums\TranslationsResponseCode StaleTranslationWorkspace=Nvl\Translations\Enums\TranslationsResponseCode::StaleTranslationWorkspace="stale_translation_workspace"
public const Nvl\Translations\Enums\TranslationsResponseCode TranslationSyncConflict=Nvl\Translations\Enums\TranslationsResponseCode::TranslationSyncConflict="translation_sync_conflict"
public const Nvl\Translations\Enums\TranslationsResponseCode TranslationWorkspaceLocked=Nvl\Translations\Enums\TranslationsResponseCode::TranslationWorkspaceLocked="translation_workspace_locked"
public const Nvl\Translations\Enums\TranslationsResponseCode Updated=Nvl\Translations\Enums\TranslationsResponseCode::Updated="updated"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Translations\Events\TranslationEntryUpdated

```text
final class Nvl\Translations\Events\TranslationEntryUpdated implements Nvl\Support\Contracts\DomainEvent
public function __construct(array $entry, int $schemaVersion=1): mixed
@param array<string, mixed> $entry
public function schemaVersion(): int
public readonly array $entry
@param array<string, mixed> $entry
public readonly int $schemaVersion
```

## Nvl\Translations\Events\TranslationsExported

```text
final class Nvl\Translations\Events\TranslationsExported implements Nvl\Support\Contracts\DomainEvent
public function __construct(array $result, int $schemaVersion=1): mixed
@param array{scopes: int, locales: int, files: int, deleted: int, target: string, paths: list<string>} $result
public function schemaVersion(): int
public readonly array $result
@param array{scopes: int, locales: int, files: int, deleted: int, target: string, paths: list<string>} $result
public readonly int $schemaVersion
```

## Nvl\Translations\Events\TranslationsImported

```text
final class Nvl\Translations\Events\TranslationsImported implements Nvl\Support\Contracts\DomainEvent
public function __construct(array $result, int $schemaVersion=1): mixed
@param array{scopes: int, files: int, entries: int, created: int, updated: int, preserved: int, conflicts: int, missing: int, warnings: list<string>} $result
public function schemaVersion(): int
public readonly array $result
@param array{scopes: int, files: int, entries: int, created: int, updated: int, preserved: int, conflicts: int, missing: int, warnings: list<string>} $result
public readonly int $schemaVersion
```

## Nvl\Translations\Events\TranslationsScanned

```text
final class Nvl\Translations\Events\TranslationsScanned implements Nvl\Support\Contracts\DomainEvent
public function __construct(array $result, int $schemaVersion=1): mixed
@param array{files: int, hits: int, scanned_at: CarbonImmutable} $result
resolved @param array{files: int, hits: int, scanned_at: Carbon\CarbonImmutable} $result
public function schemaVersion(): int
public readonly array $result
@param array{files: int, hits: int, scanned_at: CarbonImmutable} $result
resolved @param array{files: int, hits: int, scanned_at: Carbon\CarbonImmutable} $result
public readonly int $schemaVersion
```

## Nvl\Translations\Exceptions\InvalidTranslationInputException

```text
final class Nvl\Translations\Exceptions\InvalidTranslationInputException extends Nvl\Translations\Exceptions\TranslationsException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Translations\Exceptions\StaleTranslationWorkspaceException

```text
final class Nvl\Translations\Exceptions\StaleTranslationWorkspaceException extends Nvl\Translations\Exceptions\TranslationsException
public static function forEntry(string $id): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Translations\Exceptions\TranslationConflictException

```text
final class Nvl\Translations\Exceptions\TranslationConflictException extends Nvl\Translations\Exceptions\TranslationsException
public static function forIdentity(string $scope, string $identity): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Translations\Exceptions\TranslationWorkspaceLockedException

```text
final class Nvl\Translations\Exceptions\TranslationWorkspaceLockedException extends Nvl\Translations\Exceptions\TranslationsException
public static function forOperation(string $operation): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Translations\Exceptions\TranslationsException

```text
class Nvl\Translations\Exceptions\TranslationsException extends Exception implements Nvl\Support\Contracts\RespondableException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Translations\Models\TranslationEntry

```text
final class Nvl\Translations\Models\TranslationEntry extends Illuminate\Database\Eloquent\Model
public function fullKey(): string
public function getConnectionName(): ?string
public function getTable(): string
@property (array<string, mixed> | null) $conflict_metadata
@property (CarbonImmutable | null) $created_at
resolved @property (Carbon\CarbonImmutable | null) $created_at
@property string $format
@property string $group
@property string $id
@property string $identity_hash
@property bool $is_missing
@property string $key
@property (CarbonImmutable | null) $last_exported_at
resolved @property (Carbon\CarbonImmutable | null) $last_exported_at
@property (CarbonImmutable | null) $last_imported_at
resolved @property (Carbon\CarbonImmutable | null) $last_imported_at
@property string $locale
@property int $revision
@property string $scope_name
@property string $scope_type
@property (string | null) $source_hash
@property TranslationSyncStatus $sync_status
resolved @property Nvl\Translations\Enums\TranslationSyncStatus $sync_status
@property (CarbonImmutable | null) $updated_at
resolved @property (Carbon\CarbonImmutable | null) $updated_at
@property (string | null) $value
```

## Nvl\Translations\Models\TranslationOverride

```text
class Nvl\Translations\Models\TranslationOverride extends Illuminate\Database\Eloquent\Model implements Nvl\Support\Ownership\Contracts\Ownable
public function delete(): mixed
@return ($this is Pivot ? (bool | int | null) : (bool | null))
resolved @return ($this is Illuminate\Database\Eloquent\Relations\Pivot ? (bool | int | null) : (bool | null))
public function fresh(mixed $with=[]): ?static
@param (array<array-key, mixed> | string) $with
public function getConnectionName(): ?string
public function getTable(): string
public function newQueryForRestoration(mixed $ids): Illuminate\Database\Eloquent\Builder
@param (int | string | array<array-key, (int | string)>) $ids
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translations\Models\TranslationOverride>
public static function ownershipDefinition(): Nvl\Support\Ownership\ValueObjects\OwnershipDefinition
public function refresh(): static
public function refreshForUpdate(): static
public function save(array $options=[]): bool
@param array<string, mixed> $options
@property (CarbonImmutable | null) $created_at
resolved @property (Carbon\CarbonImmutable | null) $created_at
@property string $id
@property string $key
@property string $locale
@property (string | null) $owner_reference
@property (string | null) $owner_scope_key
@property int $revision
@property (CarbonImmutable | null) $updated_at
resolved @property (Carbon\CarbonImmutable | null) $updated_at
@property string $value
```

## Nvl\Translations\Models\TranslationScanRun

```text
final class Nvl\Translations\Models\TranslationScanRun extends Illuminate\Database\Eloquent\Model
public function getConnectionName(): ?string
public function getTable(): string
@property (CarbonImmutable | null) $created_at
resolved @property (Carbon\CarbonImmutable | null) $created_at
@property int $files
@property int $hits
@property string $id
@property CarbonImmutable $scanned_at
resolved @property Carbon\CarbonImmutable $scanned_at
@property (CarbonImmutable | null) $updated_at
resolved @property (Carbon\CarbonImmutable | null) $updated_at
```

## Nvl\Translations\Models\TranslationUsage

```text
final class Nvl\Translations\Models\TranslationUsage extends Illuminate\Database\Eloquent\Model
public function getConnectionName(): ?string
public function getTable(): string
@property (CarbonImmutable | null) $created_at
resolved @property (Carbon\CarbonImmutable | null) $created_at
@property string $file_path
@property string $format
@property string $full_key
@property string $id
@property string $identity_hash
@property CarbonImmutable $last_seen_at
resolved @property Carbon\CarbonImmutable $last_seen_at
@property int $line
@property (string | null) $scan_id
@property (string | null) $scope_name
@property (string | null) $scope_type
@property (CarbonImmutable | null) $updated_at
resolved @property (Carbon\CarbonImmutable | null) $updated_at
```

## Nvl\Translations\Services\TranslationCatalog

```text
final class Nvl\Translations\Services\TranslationCatalog
public function __construct(Nvl\Translations\Services\SourceTranslationWorkspace $workspace, Nvl\Translations\Services\TranslationProcessLock $lock, Nvl\Support\Events\DomainEventDispatcher $domainEvents, Nvl\Translations\Services\TranslationScopeResolver $scopeResolver): mixed
public function paginate(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $criteria=[], int $perPage=25, int $page=1): Illuminate\Contracts\Pagination\LengthAwarePaginator
@param array<string, mixed> $criteria
@return LengthAwarePaginator<int, TranslationEntry>
resolved @return Illuminate\Contracts\Pagination\LengthAwarePaginator<int, Nvl\Translations\Models\TranslationEntry>
public function statistics(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $criteria=[]): array
@param array<string, mixed> $criteria
@return array{total: int, missing: int, conflicts: int, changed: int, locales: array<string, int>, scopes: array<string, int>}
public function unused(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $scopeTokens=[], int $days=0): array
@param list<string> $scopeTokens
@return array{scanned_at: (CarbonImmutable | null), total: int, rows: list<array{id: string, scope_type: string, scope_name: string, locale: string, format: string, group: (string | null), key: string, full_key: string}>}
resolved @return array{scanned_at: (Carbon\CarbonImmutable | null), total: int, rows: list<array{id: string, scope_type: string, scope_name: string, locale: string, format: string, group: (string | null), key: string, full_key: string}>}
public function update(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Translations\Models\TranslationEntry|string $entry, array $data): Nvl\Translations\Models\TranslationEntry
@param array{value: (string | null), expected_revision: int} $data
```

## Nvl\Translations\Services\TranslationExporter

```text
final class Nvl\Translations\Services\TranslationExporter
public function __construct(Nvl\Translations\Services\TranslationScopeResolver $scopeResolver, Nvl\Translations\Services\TranslationImporter $importer, Nvl\Translations\Services\SourceTranslationWorkspace $workspace, Nvl\Translations\Services\TranslationProcessLock $lock, Nvl\Support\Events\DomainEventDispatcher $domainEvents, Nvl\Translations\Services\TranslationPathGuard $paths, Nvl\Translations\Services\TranslationArtifactWriter $artifactWriter): mixed
public function export(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $scopeTokens=[], ?array $locales=null, string $format="both", string $target="source", bool $prune=false, bool $dryRun=false): array
@param list<string> $scopeTokens
@param (list<string> | null) $locales
@return array{scopes: int, locales: int, files: int, deleted: int, target: string, paths: list<string>}
```

## Nvl\Translations\Services\TranslationImporter

```text
final class Nvl\Translations\Services\TranslationImporter
public function __construct(Nvl\Translations\Services\TranslationScopeResolver $scopeResolver, Nvl\Translations\Services\SourceTranslationWorkspace $workspace, Nvl\Translations\Services\TranslationProcessLock $lock, Nvl\Support\Events\DomainEventDispatcher $domainEvents, Nvl\Translations\Services\TranslationPathGuard $paths): mixed
public function import(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $scopeTokens=[], string $format="both", bool $dryRun=false): array
@param list<string> $scopeTokens
@return array{scopes: int, files: int, entries: int, created: int, updated: int, preserved: int, conflicts: int, missing: int, warnings: list<string>}
```

## Nvl\Translations\Services\TranslationScanner

```text
final class Nvl\Translations\Services\TranslationScanner
public function __construct(Nvl\Translations\Services\TranslationScopeResolver $scopeResolver, Nvl\Translations\Services\SourceTranslationWorkspace $workspace, Nvl\Translations\Services\TranslationProcessLock $lock, Nvl\Support\Events\DomainEventDispatcher $domainEvents, Nvl\Translations\Services\TranslationPathGuard $paths): mixed
@param TranslationScopeResolver $scopeResolver
resolved @param Nvl\Translations\Services\TranslationScopeResolver $scopeResolver
public function scan(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): array
@return array{files: int, hits: int, scanned_at: CarbonImmutable}
resolved @return array{files: int, hits: int, scanned_at: Carbon\CarbonImmutable}
public function validateConfiguration(): void
```
