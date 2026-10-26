<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Services\OwnershipConstraintInspector;
use Nvl\Support\Ownership\Services\OwnershipContextResolver;
use Nvl\Translations\Contracts\TranslationOverrideRepository;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Exceptions\StaleTranslationWorkspaceException;
use Nvl\Translations\Models\TranslationOverride;

/**
 * Persists the exact configured owner override with atomic revision checks.
 *
 * @internal
 */
final readonly class DatabaseTranslationOverrideRepository implements TranslationOverrideRepository
{
    /** Retain neutral boundaries and canonical model metadata. */
    public function __construct(private OwnershipBoundary $boundary, private OwnershipWriter $writer, private TranslationOverrideModels $models, private TranslationOverrideKeys $keys, private OwnershipConstraintInspector $constraints, private OwnershipContextResolver $context, private Factory $files, private TranslationsAuthorization $authorization) {}

    /** Read one allowed owner-scoped value. */
    public function get(Authenticatable|SystemActor $actor, string $key, string $locale): ?string
    {
        $this->authorize($actor, TranslationsAbility::ListEntries);
        $value = $this->query($key, $locale)->value('value');

        return is_string($value) ? $value : null;
    }

    /** Create or update exactly the caller's expected revision. */
    public function set(Authenticatable|SystemActor $actor, string $key, string $locale, string $value, int $expectedRevision): void
    {
        $this->authorize($actor, TranslationsAbility::UpdateEntry);
        if ($expectedRevision < 0 || $expectedRevision >= PHP_INT_MAX) {
            throw new InvalidArgumentException('Override revisions require a nonnegative bounded integer.');
        }
        $query = $this->query($key, $locale);
        if ($expectedRevision === 0) {
            $class = $this->models->modelClass();
            try {
                $this->writer->create($class, static function (array $ownership) use ($class, $key, $locale, $value): TranslationOverride {
                    $model = new $class;
                    $model->forceFill([...$ownership, 'key' => $key, 'locale' => $locale, 'value' => $value, 'revision' => 1])->save();

                    return $model;
                });

                return;
            } catch (UniqueConstraintViolationException) {
                throw StaleTranslationWorkspaceException::forEntry($key.':'.$locale);
            }
        }
        if ($query->where('revision', $expectedRevision)->update(['value' => $value, 'revision' => $expectedRevision + 1, 'updated_at' => now()]) !== 1) {
            throw StaleTranslationWorkspaceException::forEntry($key.':'.$locale);
        }
    }

    /** Delete only the expected current-owner row. */
    public function forget(Authenticatable|SystemActor $actor, string $key, string $locale, int $expectedRevision): void
    {
        $this->authorize($actor, TranslationsAbility::UpdateEntry);
        if ($expectedRevision < 1 || $this->query($key, $locale)->where('revision', $expectedRevision)->delete() !== 1) {
            throw StaleTranslationWorkspaceException::forEntry($key.':'.$locale);
        }
    }

    /** Validate the exact source and live identity uniqueness before reading or mutating.
     * @return Builder<TranslationOverride>
     */
    private function query(string $key, string $locale): Builder
    {
        $this->keys->assertAllowed($key, $locale);
        $class = $this->models->modelClass();
        $model = new $class;
        $query = $this->boundary->query($model->newQuery(), 'translations.overrides');
        $this->constraints->assertUniqueIndexes($model->getConnection(), $model->getTable(), [['owner_scope_key', 'key', 'locale']]);

        return $query->where('key', $key)->where('locale', $locale);
    }

    /** @return array{owner:array<string,mixed>,disk:string,path:string,sha256:string,size:int} */
    public function export(Authenticatable|SystemActor $actor, string $disk): array
    {
        $this->authorize($actor, TranslationsAbility::Export);
        $this->boundary->key('translations.overrides', 'export');
        $owner = $this->context->current()->requireOwner();
        $class = $this->models->modelClass();
        $rows = $this->boundary->query((new $class)->newQuery(), 'translations.overrides')->orderBy('key')->orderBy('locale')->get();
        $values = $rows->map(function (TranslationOverride $row): array {
            $this->keys->assertAllowed($row->key, $row->locale);

            return ['key' => $row->key, 'locale' => $row->locale, 'value' => $row->value, 'revision' => $row->revision];
        })->all();
        $payload = json_encode(['owner' => $owner->toArray(), 'translations' => $values], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $path = 'owners/'.substr($owner->scopeKey(), 6).'/translations/'.Str::uuid().'.json';
        $this->boundary->key('translations.overrides', 'export');
        if (! $this->files->disk($disk)->put($path, $payload, ['visibility' => 'private'])) {
            throw new \RuntimeException('Unable to write owner translation artifact.');
        }

        return ['owner' => $owner->toArray(), 'disk' => $disk, 'path' => $path, 'sha256' => hash('sha256', $payload), 'size' => strlen($payload)];
    }

    private function authorize(Authenticatable|SystemActor $actor, TranslationsAbility $ability): void
    {
        if ($actor instanceof Authenticatable && (! $actor instanceof Model || ! $actor->exists || $actor->getKey() === null || $actor->getRawOriginal($actor->getKeyName()) !== $actor->getKey() || ! $actor->newQuery()->whereKey($actor->getKey())->exists())) {
            throw new AuthorizationException('Translation actors must be persisted models.');
        }
        $this->authorization->authorize($ability, $actor);
    }
}
