<?php

declare(strict_types=1);

namespace Nvl\Translations\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Translations\Database\Factories\TranslationOverrideFactory;
use Nvl\Translations\Definitions\Tables\TranslationsTables;

/** One literal owner-specific copy override, separate from the source catalog.
 * @api
 *
 * @property string $id
 * @property string $key
 * @property string $locale
 * @property string $value
 * @property int $revision
 * @property string|null $owner_scope_key
 * @property string|null $owner_reference
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class TranslationOverride extends Model implements Ownable
{
    /** @use HasFactory<TranslationOverrideFactory> */
    use HasFactory;

    use HasOwnership;
    use HasUuids;

    protected $table = TranslationsTables::OwnerOverrides;

    protected $fillable = ['key', 'locale', 'value', 'revision'];

    protected $attributes = ['revision' => 1];

    /** Declare the default immutable captured owner; hosts may declare a native column or primary pivot. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::captured();
    }

    /** Resolve the package table while preserving subclass storage declarations. */
    public function getTable(): string
    {
        return parent::getTable() === TranslationsTables::OwnerOverrides
            ? TranslationsTables::get(TranslationsTables::OwnerOverrides) : parent::getTable();
    }

    /** Resolve the actual package or subclass source. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('translations') ?? parent::getConnectionName());
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['revision' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** Resolve the fixture factory through the advertised configured model.
     * @internal
     */
    protected static function newFactory(): TranslationOverrideFactory
    {
        return TranslationOverrideFactory::new();
    }
}
