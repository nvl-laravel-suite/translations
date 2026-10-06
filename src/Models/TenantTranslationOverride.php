<?php

declare(strict_types=1);

namespace Nvl\Translations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Nvl\Support\Config\PackageStorage;
use Nvl\Translations\Database\Factories\TenantTranslationOverrideFactory;
use Nvl\Translations\Definitions\Tables\TranslationsTables;

/**
 * One allowlisted tenant-owned copy override, separate from source catalogs.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ownership_key
 * @property string $key
 * @property string $locale
 * @property string|null $value
 * @property int $revision
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @api
 *
 * @nvl-consumer-read id
 */
final class TenantTranslationOverride extends Model
{
    /** @use HasFactory<TenantTranslationOverrideFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = TranslationsTables::TenantOverrides;

    /** @var array<string,mixed> */
    protected $attributes = ['revision' => 1];

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'ownership_key', 'key', 'locale', 'value', 'revision'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Resolve the configured package storage table. */
    public function getTable(): string
    {
        return TranslationsTables::get(TranslationsTables::TenantOverrides);
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
    protected static function newFactory(): TenantTranslationOverrideFactory
    {
        return TenantTranslationOverrideFactory::new();
    }
}
