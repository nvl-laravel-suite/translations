<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Services\OwnershipConstraintInspector;
use Nvl\Translations\Contracts\TranslationOverrideRepository;
use Nvl\Translations\Exceptions\StaleTranslationWorkspaceException;
use Nvl\Translations\Models\TranslationOverride;

/** Persists the exact configured owner override with atomic revision checks. @internal */
final readonly class DatabaseTranslationOverrideRepository implements TranslationOverrideRepository
{
    /** Retain neutral boundaries and canonical model metadata. */
    public function __construct(private OwnershipBoundary $boundary, private OwnershipWriter $writer, private TranslationOverrideModels $models, private TranslationOverrideKeys $keys, private OwnershipConstraintInspector $constraints) {}

    /** Read one allowed owner-scoped value. */
    public function get(string $key, string $locale): ?string
    {
        $value = $this->query($key, $locale)->value('value');

        return is_string($value) ? $value : null;
    }

    /** Create or update exactly the caller's expected revision. */
    public function set(string $key, string $locale, string $value, int $expectedRevision): void
    {
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
    public function forget(string $key, string $locale, int $expectedRevision): void
    {
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
}
