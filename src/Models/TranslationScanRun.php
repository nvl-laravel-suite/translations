<?php

declare(strict_types=1);

namespace Nvl\Translations\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Config\PackageStorage;
use Nvl\Translations\Database\Factories\TranslationScanRunFactory;
use Nvl\Translations\Definitions\Tables\TranslationsTables;

/**
 * Durable marker for one completed source-code translation scan.
 *
 * @property string $id
 * @property CarbonImmutable $scanned_at
 * @property int $files
 * @property int $hits
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 */
final class TranslationScanRun extends Model
{
    /** @use HasFactory<TranslationScanRunFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = TranslationsTables::ScanRuns;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scanned_at',
        'files',
        'hits',
    ];

    /**
     * Cast persisted scan-run attributes.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scanned_at' => 'immutable_datetime',
            'files' => 'integer',
            'hits' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Resolve the configured package storage table. */
    public function getTable(): string
    {
        return TranslationsTables::get(TranslationsTables::ScanRuns);
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
    protected static function newFactory(): TranslationScanRunFactory
    {
        return TranslationScanRunFactory::new();
    }
}
