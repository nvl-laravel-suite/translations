<?php

declare(strict_types=1);

namespace Nvl\Translations\Tests\Fixtures\Data;

use Carbon\CarbonImmutable;
use Nvl\Translations\Enums\TranslationFormat;
use Nvl\Translations\Enums\TranslationScopeType;
use Nvl\Translations\Enums\TranslationSyncStatus;
use Nvl\Translations\Models\TranslationEntry;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * Data transfer object for a translation entry row.
 *
 * @api
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
final class TranslationEntryPayload extends Data
{
    /**
     * @param  string  $id  Entry id
     * @param  TranslationScopeType  $scopeType  Scope type
     * @param  string  $scopeName  Scope name
     * @param  string  $locale  Locale code
     * @param  TranslationFormat  $format  Translation format
     * @param  string|null  $group  Group key for PHP translations
     * @param  string  $key  Entry key
     * @param  string|null  $value  Translation text value
     * @param  bool  $isMissing  Source file missing marker
     * @param  CarbonImmutable|null  $lastImportedAt  Last import timestamp
     * @param  CarbonImmutable  $createdAt  Creation timestamp
     * @param  CarbonImmutable  $updatedAt  Last update timestamp
     * @param  array<string, mixed>|null  $conflictMetadata  Synchronization conflict details
     */
    public function __construct(
        public readonly string $id,
        public readonly TranslationScopeType $scopeType,
        public readonly string $scopeName,
        public readonly string $locale,
        public readonly TranslationFormat $format,
        public readonly ?string $group,
        public readonly string $key,
        public readonly ?string $value,
        public readonly bool $isMissing,
        public readonly int $revision,
        public readonly TranslationSyncStatus $syncStatus,
        public readonly ?array $conflictMetadata,
        public readonly ?CarbonImmutable $lastImportedAt,
        public readonly ?CarbonImmutable $lastExportedAt,
        public readonly CarbonImmutable $createdAt,
        public readonly CarbonImmutable $updatedAt,
    ) {}

    /**
     * Build DTO from model.
     */
    public static function fromModel(TranslationEntry $entry): self
    {
        return new self(
            id: $entry->id,
            scopeType: TranslationScopeType::from($entry->scope_type),
            scopeName: $entry->scope_name,
            locale: $entry->locale,
            format: TranslationFormat::from($entry->format),
            group: $entry->group === '*' ? null : $entry->group,
            key: $entry->key,
            value: $entry->value,
            isMissing: $entry->is_missing,
            revision: $entry->revision,
            syncStatus: $entry->sync_status,
            conflictMetadata: $entry->conflict_metadata,
            lastImportedAt: $entry->last_imported_at?->toImmutable(),
            lastExportedAt: $entry->last_exported_at?->toImmutable(),
            createdAt: $entry->created_at?->toImmutable() ?? CarbonImmutable::now(),
            updatedAt: $entry->updated_at?->toImmutable() ?? CarbonImmutable::now(),
        );
    }
}
