<?php

declare(strict_types=1);

namespace Nvl\Translations\Ownership;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Contracts\OwnershipAdoptionSource;
use Nvl\Ownership\Services\OwnershipAdoptionStore;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Services\OwnershipConstraintInspector;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Translations\Services\TranslationOverrideKeys;

/**
 * Stages reviewed override history while retaining the old tables and every original identity.
 *
 * @internal
 */
final readonly class TranslationsAdoptionAdapter implements OwnershipAdoptionAdapter, OwnershipAdoptionSource
{
    /** Retain bounded package schema and explicit reviewed mappings. */
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipAdoptionStore $mappings, private OwnershipSchema $schema, private TranslationOverrideKeys $keys, private OwnershipConstraintInspector $constraints) {}

    /** @return list<string> */
    public function resources(): array
    {
        return $this->resources->get('translations.overrides')->ownership->strategy === 'unscoped' ? [] : ['translations.overrides'];
    }

    /** Resolve only the fixed historical source for this exact resource. */
    public function sourceFor(string $resource): Model
    {
        if ($resource !== 'translations.overrides') {
            throw new OwnershipConfigurationInvalid('Unknown translation adoption resource.');
        }

        return new ($this->resources->get('translations.overrides')->model);
    }

    /** Prepare additive native storage without rewriting historical tenant schema. */
    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        $definition = $this->resources->get('translations.overrides');
        $model = new $definition->model;
        $schema = $model->getConnection()->getSchemaBuilder();
        if (! $schema->hasTable($model->getTable())) {
            if ($definition->ownership->strategy !== 'captured') {
                throw new OwnershipSchemaNotReady('A column or pivot override model requires its host-supplied native schema.');
            }
            $schema->create($model->getTable(), static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('key', 191);
                $table->string('locale', 35);
                $table->text('value');
                $table->unsignedBigInteger('revision')->default(1);
                $table->timestampsTz();
            });
        }
        $this->schema->prepare('translations.overrides');
        $name = $this->constraints->name($model->getConnection(), $model->getTable(), 'index', 'override-identity');
        if (! $schema->hasIndex($model->getTable(), $name)) {
            $schema->table($model->getTable(), static fn (Blueprint $table) => $table->unique(['owner_scope_key', 'key', 'locale'], $name));
        }
    }

    /** Copy one bounded source page with the exact reviewed owner and original fields. */
    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        $definition = $this->resources->get('translations.overrides');
        $model = new $definition->model;
        $source = $this->sourceFor('translations.overrides');
        $connection = $model->getConnection();
        $rows = $connection->table($source->getTable())->when($cursor !== null, static fn ($query) => $query->where('id', '>', $cursor))->orderBy('id')->limit($limit)->get();
        $last = null;
        $connection->transaction(function () use ($rows, $plan, $definition, $model, $source, $connection, &$last): void {
            foreach ($rows as $row) {
                if (! is_string($row->id) || ! is_string($row->key) || ! is_string($row->locale)) {
                    throw new OwnershipConfigurationInvalid('Historical overrides require their original UUID and literal key.');
                }
                $mapping = $this->mappings->forRecord($plan, 'translations.overrides', $row->id);
                $owner = $mapping->requireOwner();
                if (isset($row->owner_scope_key) && $row->owner_scope_key !== $owner->scopeKey()) {
                    throw new OwnershipConfigurationInvalid('Adoption cannot relabel an existing override owner partition.');
                }
                $foreign = $definition->ownership->foreignKey;
                $native = $foreign === null ? null : ($row->{$foreign} ?? null);
                if ($native !== null && ((! is_int($native) && ! is_string($native)) || (string) $native !== $owner->key)) {
                    throw new OwnershipConfigurationInvalid('Adoption cannot relabel an existing native override owner.');
                }
                if (isset($row->tenant_id) && $row->tenant_id !== $mapping->legacyTenantId) {
                    throw new OwnershipConfigurationInvalid('A translation mapping must preserve its historical tenant identity.');
                }
                $this->keys->assertAllowed($row->key, $row->locale);
                $attributes = ['owner_scope_key' => $owner->scopeKey()];
                if ($definition->ownership->strategy === 'captured') {
                    $attributes['owner_reference'] = json_encode($owner->toArray(), JSON_THROW_ON_ERROR);
                } elseif ($definition->ownership->strategy === 'column') {
                    $attributes[$definition->ownership->foreignKey ?? throw new OwnershipConfigurationInvalid] = $owner->key;
                }
                if ($source->getTable() !== $model->getTable()) {
                    $attributes += ['key' => $row->key, 'locale' => $row->locale, 'value' => $row->value, 'revision' => $row->revision, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at];
                }
                $existing = $connection->table($model->getTable())->where('id', $row->id)->first();
                if ($existing !== null && isset($existing->owner_scope_key) && $existing->owner_scope_key !== $owner->scopeKey()) {
                    throw new OwnershipConfigurationInvalid('Adoption cannot overwrite a differently owned staged override.');
                }
                $connection->table($model->getTable())->updateOrInsert(['id' => $row->id], $attributes);
                if ($definition->ownership->strategy === 'pivot') {
                    $ownership = $definition->ownership;
                    $connection->table($ownership->table ?? throw new OwnershipConfigurationInvalid)->insertOrIgnore([$ownership->resourceKey ?? throw new OwnershipConfigurationInvalid => $row->id, $ownership->ownerKey ?? throw new OwnershipConfigurationInvalid => $owner->key, 'owner_scope_key' => $owner->scopeKey()]);
                }
                $last = $row->id;
            }
        });

        return new OwnershipBackfillResult($rows->count() === $limit ? $last : null, $rows->count());
    }

    /** Verify canonical owners, live identity uniqueness and preserved source copy. */
    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        $this->schema->verify('translations.overrides');
        $model = new ($this->resources->get('translations.overrides')->model);
        $connection = $model->getConnection();
        $this->constraints->assertUniqueIndexes($connection, $model->getTable(), [['owner_scope_key', 'key', 'locale']]);
        foreach ($connection->table($model->getTable())->orderBy('id')->cursor() as $row) {
            if (! is_string($row->key) || ! is_string($row->locale)) {
                throw new OwnershipConfigurationInvalid('Override identity fields must remain literal strings.');
            }
            $this->keys->assertAllowed($row->key, $row->locale);
        }
        $source = $this->sourceFor('translations.overrides');
        if ($source->getTable() !== $model->getTable()) {
            foreach ($connection->table($source->getTable())->orderBy('id')->cursor() as $row) {
                $target = $connection->table($model->getTable())->where('id', $row->id)->first();
                foreach (['key', 'locale', 'value', 'revision', 'created_at', 'updated_at'] as $field) {
                    if ($target === null || $target->{$field} !== $row->{$field}) {
                        return new OwnershipVerification(['historical_copy_mismatch']);
                    }
                }
            }
        }

        return new OwnershipVerification([]);
    }

    /** Constrain primary owners and literal identities before the coordinator publishes activation. */
    public function activate(OwnershipAdoptionPlan $plan): void
    {
        $this->schema->constrain('translations.overrides');
    }
}
