<?php

declare(strict_types=1);

namespace Nvl\Translations\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Config\PackageStorage;
use Nvl\Translations\Database\Factories\TranslationUsageFactory;
use Nvl\Translations\Definitions\Tables\TranslationsTables;
use Nvl\Translations\Support\TranslationIdentity;

/**
 * Captured translation usage hit in source code.
 *
 * @property string $id
 * @property string $identity_hash
 * @property string|null $scan_id
 * @property string|null $scope_type
 * @property string|null $scope_name
 * @property string $format
 * @property string $full_key
 * @property string $file_path
 * @property int $line
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 */
final class TranslationUsage extends Model
{
    /** @use HasFactory<TranslationUsageFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = TranslationsTables::Usages;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'identity_hash',
        'scan_id',
        'scope_type',
        'scope_name',
        'format',
        'full_key',
        'file_path',
        'line',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'last_seen_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (TranslationUsage $usage): void {
            $identityHash = $usage->getAttribute('identity_hash');

            if ($usage->isDirty(['scope_type', 'scope_name', 'format', 'full_key', 'file_path', 'line'])
                || ! is_string($identityHash)
                || $identityHash === '') {
                $usage->identity_hash = TranslationIdentity::usage(
                    $usage->scope_type,
                    $usage->scope_name,
                    $usage->format,
                    $usage->full_key,
                    $usage->file_path,
                    $usage->line,
                );
            }
        });
    }

    /** Resolve the configured package storage table. */
    public function getTable(): string
    {
        return TranslationsTables::get(TranslationsTables::Usages);
    }

    /** Resolve the package connection through shared infrastructure defaults. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('translations') ?? parent::getConnectionName());
    }

    /**
     * Return the package's runtime fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): TranslationUsageFactory
    {
        return TranslationUsageFactory::new();
    }
}
