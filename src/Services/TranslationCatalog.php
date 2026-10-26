<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Enums\TranslationScopeType;
use Nvl\Translations\Enums\TranslationSyncStatus;
use Nvl\Translations\Events\TranslationEntryUpdated;
use Nvl\Translations\Exceptions\InvalidTranslationInputException;
use Nvl\Translations\Exceptions\StaleTranslationWorkspaceException;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Models\TranslationScanRun;
use Nvl\Translations\Models\TranslationUsage;
use Nvl\Translations\Support\TranslationScope;
use Nvl\Translations\Support\TranslationValueHash;

/**
 * Authorized catalog queries and revision-checked edits.
 *
 * @api
 */
final class TranslationCatalog
{
    public function __construct(private readonly SourceTranslationWorkspace $workspace, private readonly TranslationProcessLock $lock, private readonly DomainEventDispatcher $domainEvents, private readonly TranslationScopeResolver $scopeResolver) {}

    /** @param array<string,mixed> $criteria
     * @return LengthAwarePaginator<int,TranslationEntry> */
    public function paginate(Authenticatable|SystemActor $actor, array $criteria = [], int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        $this->workspace->authorize($actor, TranslationsAbility::ListEntries);

        return $this->query($criteria)->paginate(max(1, min(200, $perPage)), ['*'], 'page', max(1, $page));
    }

    /** @param array<string,mixed> $criteria
     * @return array{total:int,missing:int,conflicts:int,changed:int,locales:array<string,int>,scopes:array<string,int>} */
    public function statistics(Authenticatable|SystemActor $actor, array $criteria = []): array
    {
        $this->workspace->authorize($actor, TranslationsAbility::ListEntries);
        $query = $this->query($criteria)->reorder();

        return $this->totals($query) + ['locales' => $this->locales($query), 'scopes' => $this->scopes($query)];
    }

    /** @param array{value:string|null,expected_revision:int} $data */
    public function update(Authenticatable|SystemActor $actor, TranslationEntry|string $entry, array $data): TranslationEntry
    {
        $this->workspace->authorize($actor, TranslationsAbility::UpdateEntry, $entry instanceof TranslationEntry ? $entry : null);
        $data = $this->validatedUpdate($data);

        if ($entry instanceof TranslationEntry && (! $entry->exists || $entry->getConnection() !== (new TranslationEntry)->getConnection() || $entry->getRawOriginal($entry->getKeyName()) !== $entry->getKey())) {
            throw new InvalidTranslationInputException('Translation entries require their persisted source identity.');
        }
        $entryId = $entry instanceof TranslationEntry ? $entry->id : $entry;
        $updated = $this->lock->execute(
            'update',
            fn (): TranslationEntry => (new TranslationEntry)->getConnection()->transaction(function () use ($actor, $entryId, $data): TranslationEntry {
                $model = TranslationEntry::query()
                    ->lockForUpdate()
                    ->findOrFail($entryId);
                $this->workspace->authorize($actor, TranslationsAbility::UpdateEntry, $model);

                if ($model->revision !== $data['expected_revision']) {
                    throw StaleTranslationWorkspaceException::forEntry($model->id);
                }

                $matchesSource = ! $model->is_missing
                    && $model->source_hash !== null
                    && hash_equals($model->source_hash, TranslationValueHash::make($data['value']));

                $model->fill([
                    'value' => $data['value'],
                    'is_missing' => false,
                    'sync_status' => $matchesSource ? 'synchronized' : 'edited',
                    'conflict_metadata' => null,
                ]);
                $model->save();

                $model->refresh();

                $facts = [];
                foreach ($model->toArray() as $key => $value) {
                    if (! is_string($key)) {
                        throw new InvalidTranslationInputException('Translation facts require named attributes.');
                    }
                    $facts[$key] = $value;
                }
                $this->domainEvents->dispatch(new TranslationEntryUpdated($facts), $model->getConnection());

                return $model;
            }),
        );

        return $updated;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array{value: string|null, expected_revision: int}
     */
    private function validatedUpdate(array $data): array
    {
        if (array_diff(array_keys($data), ['value', 'expected_revision']) !== [] || ! array_key_exists('value', $data) || ($data['value'] !== null && ! is_string($data['value'])) || ! isset($data['expected_revision']) || ! is_int($data['expected_revision']) || $data['expected_revision'] < 1) {
            throw new InvalidTranslationInputException('A nullable string value and positive integer expected_revision are required.');
        }
        if ($data['value'] !== null && ! mb_check_encoding($data['value'], 'UTF-8')) {
            throw new InvalidTranslationInputException('Translation values must contain valid UTF-8 text.');
        }

        return ['value' => $data['value'], 'expected_revision' => $data['expected_revision']];
    }

    /**
     * Return the four scalar statistics in one aggregate query.
     *
     * @param  Builder<TranslationEntry>  $query
     * @return array{total: int, missing: int, conflicts: int, changed: int}
     */
    private function totals(Builder $query): array
    {
        $row = (clone $query)
            ->toBase()
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('SUM(CASE WHEN is_missing = ? THEN 1 ELSE 0 END) AS missing_count', [true])
            ->selectRaw('SUM(CASE WHEN sync_status = ? THEN 1 ELSE 0 END) AS conflict_count', [
                TranslationSyncStatus::Conflict->value,
            ])
            ->selectRaw('SUM(CASE WHEN source_hash IS NOT NULL AND sync_status IN (?, ?) THEN 1 ELSE 0 END) AS changed_count', [
                TranslationSyncStatus::Edited->value,
                TranslationSyncStatus::Conflict->value,
            ])
            ->first();
        $values = is_object($row) ? (array) $row : [];

        return [
            'total' => self::normalizeCount($values['total_count'] ?? null),
            'missing' => self::normalizeCount($values['missing_count'] ?? null),
            'conflicts' => self::normalizeCount($values['conflict_count'] ?? null),
            'changed' => self::normalizeCount($values['changed_count'] ?? null),
        ];
    }

    /**
     * Return the top one hundred locale counts.
     *
     * @param  Builder<TranslationEntry>  $query
     * @return array<string, int>
     */
    private function locales(Builder $query): array
    {
        $rows = (clone $query)
            ->toBase()
            ->selectRaw("COALESCE(NULLIF(TRIM(locale), ''), 'unknown') AS aggregate_key")
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->groupBy('aggregate_key')
            ->orderByDesc('aggregate_count')
            ->orderBy('aggregate_key')
            ->limit(100)
            ->get();
        $locales = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $key = $values['aggregate_key'] ?? 'unknown';

            $locales[is_string($key) && $key !== '' ? $key : 'unknown'] = self::normalizeCount(
                $values['aggregate_count'] ?? null,
            );
        }

        return $locales;
    }

    /**
     * Return the top one hundred canonical scope-token counts.
     *
     * @param  Builder<TranslationEntry>  $query
     * @return array<string, int>
     */
    private function scopes(Builder $query): array
    {
        $typeExpression = "COALESCE(NULLIF(TRIM(scope_type), ''), 'unknown')";
        $nameExpression = "CASE WHEN COALESCE(NULLIF(TRIM(scope_type), ''), 'unknown') IN ('app', 'unknown') THEN '' ELSE COALESCE(TRIM(scope_name), '') END";
        $rows = (clone $query)
            ->toBase()
            ->selectRaw("{$typeExpression} AS aggregate_type")
            ->selectRaw("{$nameExpression} AS aggregate_name")
            ->selectRaw('COUNT(*) AS aggregate_count')
            ->groupBy('aggregate_type', 'aggregate_name')
            ->orderByDesc('aggregate_count')
            ->orderBy('aggregate_type')
            ->orderBy('aggregate_name')
            ->limit(100)
            ->get();
        $scopes = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $type = is_string($values['aggregate_type'] ?? null) ? trim($values['aggregate_type']) : '';
            $name = is_string($values['aggregate_name'] ?? null) ? trim($values['aggregate_name']) : '';
            $key = self::scopeKey($type, $name);
            $scopes[$key] = ($scopes[$key] ?? 0) + self::normalizeCount($values['aggregate_count'] ?? null);
        }

        return $scopes;
    }

    /**
     * Build the same scope token used by translation commands.
     */
    private static function scopeKey(string $type, string $name): string
    {
        if ($type === TranslationScopeType::App->value) {
            return TranslationScopeType::App->value;
        }

        if ($type === '') {
            return 'unknown';
        }

        return $name !== '' ? "{$type}:{$name}" : $type;
    }

    /**
     * Normalize database-driver aggregate values to non-negative integers.
     */
    private static function normalizeCount(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        $validated = is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
            : false;

        return is_int($validated) ? $validated : 0;
    }

    /**
     * Generate unused translation report.
     *
     * @param  list<string>  $scopeTokens
     * @return array{
     *     scanned_at:CarbonImmutable|null,
     *     total:int,
     *     rows:list<array{
     *         id:string,
     *         scope_type:string,
     *         scope_name:string,
     *         locale:string,
     *         format:string,
     *         group:string|null,
     *         key:string,
     *         full_key:string
     *     }>
     * }
     */
    public function unused(Authenticatable|SystemActor $actor, array $scopeTokens = [], int $days = 0): array
    {
        $this->workspace->authorize($actor, TranslationsAbility::ListEntries);
        if ($days < 0 || $days > 3650) {
            throw new InvalidTranslationInputException('Unused lookback must be between 0 and 3650 days.');
        }
        $entryQuery = TranslationEntry::query()
            ->where('is_missing', false)
            ->orderBy('scope_type')
            ->orderBy('scope_name')
            ->orderBy('locale')
            ->orderBy('group')
            ->orderBy('key');
        $scopes = $this->scopeResolver->resolveScopes($scopeTokens);
        $this->applyScopeConstraint($entryQuery, $scopes);

        $usageQuery = TranslationUsage::query();
        $this->applyScopeConstraint($usageQuery, $scopes);
        $scannedAt = null;

        if ($days > 0) {
            $scannedAt = CarbonImmutable::now('UTC')->subDays($days);
            $usageQuery->where('last_seen_at', '>=', $scannedAt);
        } else {
            $latestScan = TranslationScanRun::query()
                ->orderByDesc('scanned_at')
                ->orderByDesc('created_at')
                ->first(['id', 'scanned_at']);

            if ($latestScan !== null) {
                $scannedAt = $latestScan->scanned_at->toImmutable();
                $usageQuery->where('scan_id', $latestScan->id);
            } else {
                $usageQuery->whereRaw('1 = 0');
            }
        }

        $usedScoped = [];

        foreach ($usageQuery->get(['scope_type', 'scope_name', 'format', 'full_key']) as $usage) {
            $fullKey = (string) $usage->full_key;
            if ($usage->scope_type === null || $usage->scope_name === null) {
                continue;
            }

            $scopedKey = sprintf(
                '%s|%s|%s|%s',
                (string) $usage->scope_type,
                (string) $usage->scope_name,
                (string) $usage->format,
                $fullKey,
            );
            $usedScoped[$scopedKey] = true;
        }

        $configuredAllowlist = Config::get('nvl-translations.scan_allowlist', []);
        $allowlist = is_array($configuredAllowlist)
            ? array_values(array_filter(
                $configuredAllowlist,
                static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '',
            ))
            : [];

        $unused = [];
        foreach ($entryQuery->cursor() as $entry) {
            $fullKey = $entry->format === 'php' && $entry->group !== '*'
                ? $entry->group.'.'.$entry->key
                : (string) $entry->key;

            if ($this->matchesAllowlist($fullKey, $allowlist)) {
                continue;
            }

            $scopedKey = sprintf(
                '%s|%s|%s|%s',
                (string) $entry->scope_type,
                (string) $entry->scope_name,
                (string) $entry->format,
                $fullKey,
            );
            if (isset($usedScoped[$scopedKey])) {
                continue;
            }

            $unused[] = [
                'id' => (string) $entry->id,
                'scope_type' => (string) $entry->scope_type,
                'scope_name' => (string) $entry->scope_name,
                'locale' => (string) $entry->locale,
                'format' => (string) $entry->format,
                'group' => $entry->group === '*' ? null : $entry->group,
                'key' => (string) $entry->key,
                'full_key' => $fullKey,
            ];
        }

        return [
            'scanned_at' => $scannedAt instanceof CarbonImmutable ? $scannedAt : null,
            'total' => count($unused),
            'rows' => $unused,
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<TranslationScope>  $scopes
     */
    private function applyScopeConstraint(Builder $query, array $scopes): void
    {
        if ($scopes === []) {
            return;
        }

        $query->where(static function (Builder $builder) use ($scopes): void {
            foreach ($scopes as $scope) {
                $builder->orWhere(static function (Builder $nested) use ($scope): void {
                    $nested->getQuery()
                        ->where('scope_type', $scope->type->value)
                        ->where('scope_name', $scope->name);
                });
            }
        });
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAllowlist(string $fullKey, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }

            if (Str::is($pattern, $fullKey)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $criteria
     * @return Builder<TranslationEntry> */
    private function query(array $criteria): Builder
    {
        $columns = ['scope_type', 'scope_name', 'locale', 'format', 'group', 'key'];
        $allowed = [...$columns, 'search', 'key_contains', 'missing_value', 'changed_since_import', 'is_missing', 'sort'];
        if (array_diff(array_keys($criteria), $allowed) !== []) {
            throw new InvalidTranslationInputException('Unknown catalog criteria.');
        }
        $query = TranslationEntry::query();
        foreach ($columns as $column) {
            if (! array_key_exists($column, $criteria)) {
                continue;
            }
            $values = is_array($criteria[$column]) ? $criteria[$column] : [$criteria[$column]];
            if (! array_is_list($values) || count($values) > 100) {
                throw new InvalidTranslationInputException('Catalog criteria must be bounded lists.');
            }
            foreach ($values as $value) {
                if (! is_string($value)) {
                    throw new InvalidTranslationInputException('Catalog criteria require strings.');
                }
            }
            $query->whereIn($column, $values);
        }
        if (array_key_exists('key_contains', $criteria)) {
            if (! is_string($criteria['key_contains'])) {
                throw new InvalidTranslationInputException('Key matching requires a string.');
            }
            $column = $query->getQuery()->getGrammar()->wrap('key');
            self::ensureSafeWrappedColumn($column);
            $query->whereRaw("{$column} LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $criteria['key_contains']).'%']);
        }
        foreach (['search' => 'filterSearch', 'missing_value' => 'filterMissingValue', 'changed_since_import' => 'filterChangedSinceImport', 'is_missing' => 'filterIsMissing'] as $key => $method) {
            if (array_key_exists($key, $criteria)) {
                if ($key === 'search' ? ! is_string($criteria[$key]) : ! is_bool($criteria[$key])) {
                    throw new InvalidTranslationInputException('Invalid catalog criterion type.');
                }
                $this->{$method}($query, $criteria[$key]);
            }
        }
        $sorts = $criteria['sort'] ?? ['-updated_at'];
        if (! is_array($sorts) || ! array_is_list($sorts) || count($sorts) > 9) {
            throw new InvalidTranslationInputException('Invalid catalog sorts.');
        }
        foreach ($sorts as $sort) {
            if (! is_string($sort) || ! in_array(ltrim($sort, '-'), [...$columns, 'updated_at', 'last_imported_at', 'id'], true)) {
                throw new InvalidTranslationInputException('Unknown catalog sort.');
            }
            $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');
        }

        return $query->orderBy('id');
    }

    /**
     * Search by key, group, scope, or value.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    private function filterSearch(Builder $query, mixed $value): Builder
    {
        $search = is_string($value) ? mb_strtolower(trim($value)) : '';
        if ($search === '') {
            return $query;
        }

        $grammar = $query->getQuery()->getGrammar();
        $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        return $query->where(static function (Builder $builder) use ($grammar, $needle): void {
            foreach (['scope_type', 'scope_name', 'locale', 'format', 'group', 'key', 'value'] as $index => $column) {
                $wrapped = $grammar->wrap($column);
                self::ensureSafeWrappedColumn($wrapped);
                $expression = "LOWER(COALESCE({$wrapped}, '')) LIKE ? ESCAPE '!'";

                if ($index === 0) {
                    $builder->whereRaw($expression, [$needle]);
                } else {
                    $builder->orWhereRaw($expression, [$needle]);
                }
            }
        });
    }

    /**
     * Filter rows by missing value state.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    private function filterMissingValue(Builder $query, mixed $value): Builder
    {
        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return $query;
        }

        if ($enabled) {
            return $query->where(static function (Builder $builder): void {
                $builder->whereNull('value')->orWhere('value', '');
            });
        }

        return $query->whereNotNull('value')->where('value', '!=', '');
    }

    /**
     * Filter rows with database values that are not synchronized to source.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    private function filterChangedSinceImport(Builder $query, mixed $value): Builder
    {
        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return $query;
        }

        $changedStatuses = [
            TranslationSyncStatus::Edited->value,
            TranslationSyncStatus::Conflict->value,
        ];

        if ($enabled) {
            return $query->whereIn('sync_status', $changedStatuses);
        }

        return $query->whereNotIn('sync_status', $changedStatuses);
    }

    /**
     * Filter rows marked as missing in source files.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    private function filterIsMissing(Builder $query, mixed $value): Builder
    {
        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return $query;
        }

        return $query->where('is_missing', $enabled);
    }

    /**
     * Prove that a database grammar returned only a quoted, allowlisted identifier.
     *
     * @phpstan-assert literal-string $column
     */
    private static function ensureSafeWrappedColumn(string $column): void
    {
        if (preg_match('/\\A(?:[`"]?[a-z_]+[`"]?|\\[[a-z_]+\\])\\z/', $column) !== 1) {
            throw new TranslationsException('The database grammar returned an unsafe column identifier.');
        }
    }
}
