<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Exceptions\InvalidTranslationInputException;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Models\TranslationUsage;
use Nvl\Translations\Support\LegacyTranslationCatalogRows;
use stdClass;

/**
 * Stages an existing standalone catalog for an explicit host cutover.
 *
 * @api
 *
 * @phpstan-import-type Row from LegacyTranslationCatalogRows
 *
 * @phpstan-type Options array{connection:?string, entries_table:string, usages_table:string, source_values:array<string, string|null>}
 * @phpstan-type CatalogTable array{source:string, target:string, columns:list<string>, native:list<string>}
 * @phpstan-type Review array{
 *     schema_fingerprint:string, fingerprint:string,
 *     entries:int, usages:int, staged_entries:int, staged_usages:int, conflicts:list<string>
 * }
 */
final readonly class TranslationCatalogAdopter
{
    private const int BatchSize = 50;

    /** Reuse the permission and lock that protect the source catalog workspace. */
    public function __construct(
        private DatabaseManager $database,
        private SourceTranslationWorkspace $workspace,
        private TranslationProcessLock $lock,
        private LegacyTranslationCatalogRows $rows,
    ) {}

    /**
     * Inspect source and staging tables without writing. The source must be quiescent.
     *
     * Accepted options are connection, entries_table, usages_table and source_values.
     * source_values maps legacy entry UUIDs to reviewed nullable source values.
     *
     * @param  array<array-key, mixed>  $source
     * @return Review
     */
    public function plan(Authenticatable|SystemActor $actor, array $source = []): array
    {
        $this->workspace->authorize($actor, TranslationsAbility::Synchronize);

        return $this->review($this->options($source));
    }

    /**
     * Copy in committed batches. Retry with the same fingerprint to verify and reuse staged rows.
     *
     * @param  array<array-key, mixed>  $source
     * @return Review
     */
    public function apply(Authenticatable|SystemActor $actor, string $fingerprint, array $source = []): array
    {
        $this->workspace->authorize($actor, TranslationsAbility::Synchronize);
        $options = $this->options($source);
        $this->requireCommittedCatalog();

        return $this->lock->execute('adopt', function () use ($fingerprint, $options): array {
            $review = $this->review($options);
            $this->assertReviewed($review, $fingerprint);

            if ($review['staged_entries'] !== $review['entries'] || $review['staged_usages'] !== $review['usages']) {
                $this->review($options, write: true);
            }

            return $this->reconcile($options, $fingerprint);
        });
    }

    /**
     * Reconcile all staged rows with the reviewed source before activating host integration.
     *
     * @param  array<array-key, mixed>  $source
     * @return Review
     */
    public function verify(Authenticatable|SystemActor $actor, string $fingerprint, array $source = []): array
    {
        $this->workspace->authorize($actor, TranslationsAbility::Synchronize);
        $options = $this->options($source);
        $this->requireCommittedCatalog();

        return $this->lock->execute('verify adoption', fn (): array => $this->reconcile($options, $fingerprint));
    }

    private function requireCommittedCatalog(): void
    {
        if ((new TranslationEntry)->getConnection()->transactionLevel() !== 0) {
            throw new TranslationsException('Translation catalog adoption requires a committed catalog outside existing transactions.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $source
     * @return Options
     */
    private function options(array $source): array
    {
        if (array_diff(array_keys($source), ['connection', 'entries_table', 'usages_table', 'source_values']) !== []) {
            throw new InvalidTranslationInputException('Unknown translation catalog source option.');
        }
        $connection = $source['connection'] ?? null;
        if ($connection !== null && (! is_string($connection) || $connection === '')) {
            throw new InvalidTranslationInputException('The legacy catalog connection must be a configured connection name.');
        }
        $baselines = $source['source_values'] ?? [];
        if (! is_array($baselines)) {
            throw new InvalidTranslationInputException('source_values must map legacy entry UUIDs to source values.');
        }
        $sourceValues = [];
        foreach ($baselines as $id => $value) {
            if (! is_string($id) || ! Str::isUuid($id)
                || ($value !== null && (! is_string($value) || str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')))) {
                throw new InvalidTranslationInputException('source_values must map legacy entry UUIDs to nullable UTF-8 strings.');
            }
            $sourceValues[$id] = $value;
        }

        return [
            'connection' => $connection,
            'entries_table' => $this->tableName($source['entries_table'] ?? 'translation_entries'),
            'usages_table' => $this->tableName($source['usages_table'] ?? 'translation_usages'),
            'source_values' => $sourceValues,
        ];
    }

    private function tableName(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}(?:\.[A-Za-z_][A-Za-z0-9_]{0,62})?\z/', $value) !== 1) {
            throw new InvalidTranslationInputException('Legacy translation table names must be plain or schema-qualified identifiers.');
        }

        return $value;
    }

    /**
     * @param  Options  $options
     * @return Review
     */
    private function review(array $options, bool $write = false): array
    {
        $entry = new TranslationEntry;
        $usage = new TranslationUsage;
        $origin = $this->database->connection($options['connection']);
        $destination = $entry->getConnection();
        $tables = [
            'entries' => ['source' => $options['entries_table'], 'target' => $entry->getTable(), 'columns' => LegacyTranslationCatalogRows::EntryColumns, 'native' => LegacyTranslationCatalogRows::EntryNativeColumns],
            'usages' => ['source' => $options['usages_table'], 'target' => $usage->getTable(), 'columns' => LegacyTranslationCatalogRows::UsageColumns, 'native' => LegacyTranslationCatalogRows::UsageNativeColumns],
        ];
        $schema = [];

        foreach ($tables as $table) {
            $schema[] = $this->schema($origin, $table['source'], $table['columns'], legacy: true);
            $schema[] = $this->schema($destination, $table['target'], [...$table['columns'], ...$table['native']], legacy: false);
        }

        $schemaFingerprint = hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR));
        $baselines = $options['source_values'];
        $entries = $this->transfer($origin, $destination, $tables['entries'], $baselines, entries: true, write: $write);
        if ($baselines !== []) {
            throw new InvalidTranslationInputException('source_values references a missing legacy entry ['.array_key_first($baselines).'].');
        }
        $usages = $this->transfer($origin, $destination, $tables['usages'], $baselines, entries: false, write: $write);

        return [
            'schema_fingerprint' => $schemaFingerprint,
            'fingerprint' => hash('sha256', $schemaFingerprint.$entries['digest'].$usages['digest']),
            'entries' => $entries['count'],
            'usages' => $usages['count'],
            'staged_entries' => $entries['staged'],
            'staged_usages' => $usages['staged'],
            'conflicts' => [...$entries['conflicts'], ...$usages['conflicts']],
        ];
    }

    /**
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function schema(Connection $connection, string $table, array $required, bool $legacy): array
    {
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable($table)) {
            throw new InvalidTranslationInputException("Translation catalog table [{$table}] does not exist.");
        }

        $columns = $schema->getColumns($table);
        $names = array_column($columns, 'name');
        $missing = array_diff($required, $names);
        if ($missing !== []) {
            throw new InvalidTranslationInputException("Translation catalog table [{$table}] is missing columns [".implode(', ', $missing).'].');
        }
        $extra = array_diff($names, $required);
        if ($legacy && $extra !== []) {
            throw new InvalidTranslationInputException("Legacy translation catalog table [{$table}] has unmapped columns [".implode(', ', $extra).'].');
        }
        if (! $schema->hasIndex($table, ['id'], 'primary') || (! $legacy && ! $schema->hasIndex($table, ['identity_hash'], 'unique'))) {
            throw new InvalidTranslationInputException("Translation catalog table [{$table}] is missing its primary or identity key.");
        }

        return [
            'connection' => $connection->getName(),
            'database' => $connection->getDatabaseName(),
            'driver' => $connection->getDriverName(),
            'table' => $connection->getTablePrefix().$table,
            'columns' => $columns,
            'indexes' => $schema->getIndexes($table),
        ];
    }

    /**
     * @param  CatalogTable  $table
     * @param  array<string, string|null>  $baselines
     * @return array{count:int, staged:int, digest:string, conflicts:list<string>}
     */
    private function transfer(Connection $origin, Connection $destination, array $table, array &$baselines, bool $entries, bool $write): array
    {
        $digest = hash_init('sha256');
        $count = 0;
        $staged = 0;
        $conflicts = [];
        $seen = [];
        $source = $origin->table($table['source'])->useWritePdo()->select($table['columns']);
        $target = $destination->table($table['target'])->useWritePdo();

        foreach ($source->lazyById(self::BatchSize)->chunk(self::BatchSize) as $slice) {
            $batch = [];
            foreach ($slice as $record) {
                $normalized = $entries ? $this->rows->entry($record, $baselines) : $this->rows->usage($record);
                $id = $normalized['id'];
                $identity = $normalized['identity_hash'];
                $count++;
                hash_update($digest, json_encode([$record, $normalized], JSON_THROW_ON_ERROR)."\n");

                if (isset($seen[$identity])) {
                    $conflicts[] = "Legacy table [{$table['source']}] has conflicting IDs [{$seen[$identity]}, {$id}] for one normalized identity.";

                    continue;
                }
                $seen[$identity] = $id;
                $batch[] = $normalized;
            }

            if ($batch === []) {
                continue;
            }

            $existing = (clone $target)->whereIn('id', array_column($batch, 'id'))
                ->orWhereIn('identity_hash', array_column($batch, 'identity_hash'))->get();
            $byId = $existing->keyBy('id');
            $byIdentity = $existing->keyBy('identity_hash');
            $pending = [];

            foreach ($batch as $attributes) {
                $id = $attributes['id'];
                $match = $byId->get($id) ?? $byIdentity->get($attributes['identity_hash']);
                if ($match instanceof stdClass) {
                    if ($this->rows->matches($match, $attributes)) {
                        $staged++;
                    } else {
                        $existingId = is_string($match->id) ? $match->id : 'invalid UUID';
                        $conflicts[] = "Target [{$table['target']}] conflicts with source ID [{$id}] at existing ID [{$existingId}].";
                    }

                    continue;
                }
                $pending[] = $attributes;
            }

            if ($write) {
                if ($conflicts !== []) {
                    throw new TranslationsException('Translation catalog changed while staging: '.$conflicts[0]);
                }
                if ($pending !== []) {
                    $destination->transaction(static fn (): bool => (clone $target)->insert($pending));
                    $staged += count($pending);
                }
            }
        }

        if ($conflicts === [] && $target->count() !== $staged) {
            $conflicts[] = "Target [{$table['target']}] contains rows outside the reviewed legacy catalog.";
        }

        return ['count' => $count, 'staged' => $staged, 'digest' => hash_final($digest), 'conflicts' => $conflicts];
    }

    /** @param Review $review */
    private function assertReviewed(array $review, string $fingerprint): void
    {
        if (! hash_equals($review['fingerprint'], $fingerprint)) {
            throw new TranslationsException('The legacy catalog schema or data changed. Review a new adoption plan.');
        }
        if ($review['conflicts'] !== []) {
            throw new TranslationsException('The translation catalog has adoption conflicts. '.$review['conflicts'][0]);
        }
    }

    /**
     * @param  Options  $options
     * @return Review
     */
    private function reconcile(array $options, string $fingerprint): array
    {
        $review = $this->review($options);
        $this->assertReviewed($review, $fingerprint);
        if ($review['staged_entries'] !== $review['entries'] || $review['staged_usages'] !== $review['usages']) {
            throw new TranslationsException('The translation catalog is not fully staged. Resume the reviewed adoption.');
        }

        return $review;
    }
}
