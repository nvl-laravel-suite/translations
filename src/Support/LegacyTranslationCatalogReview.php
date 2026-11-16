<?php

declare(strict_types=1);

namespace Nvl\Translations\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Nvl\Translations\Exceptions\InvalidTranslationInputException;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Models\TranslationUsage;
use stdClass;

/**
 * Validates adoption options and compares the legacy catalog with the staged native rows.
 *
 * Shared by the compatibility adopter service and the nvl:adopt adapter so both use one mapping and one conflict rule.
 *
 * @internal
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
final readonly class LegacyTranslationCatalogReview
{
    private const int BatchSize = 50;

    public function __construct(
        private DatabaseManager $database,
        private LegacyTranslationCatalogRows $rows,
    ) {}

    /**
     * Validate the accepted options: connection, entries_table, usages_table and source_values.
     *
     * @param  array<array-key, mixed>  $source
     * @return Options
     */
    public function options(array $source): array
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

    /**
     * @param  Options  $options
     */
    public function origin(array $options): Connection
    {
        return $this->database->connection($options['connection']);
    }

    public function destination(): Connection
    {
        return (new TranslationEntry)->getConnection();
    }

    /**
     * @param  Options  $options
     * @return array{entries: CatalogTable, usages: CatalogTable}
     */
    public function tables(array $options): array
    {
        return [
            'entries' => ['source' => $options['entries_table'], 'target' => (new TranslationEntry)->getTable(), 'columns' => LegacyTranslationCatalogRows::EntryColumns, 'native' => LegacyTranslationCatalogRows::EntryNativeColumns],
            'usages' => ['source' => $options['usages_table'], 'target' => (new TranslationUsage)->getTable(), 'columns' => LegacyTranslationCatalogRows::UsageColumns, 'native' => LegacyTranslationCatalogRows::UsageNativeColumns],
        ];
    }

    /**
     * Compare the whole legacy catalog with the staged rows. With write, insert missing rows in committed batches.
     *
     * @param  Options  $options
     * @return Review
     */
    public function review(array $options, bool $write = false): array
    {
        $origin = $this->origin($options);
        $destination = $this->destination();
        $tables = $this->tables($options);
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
     * Split normalized rows into rows already staged unchanged, rows that conflict with staged rows, and rows to insert.
     *
     * @param  CatalogTable  $table
     * @param  list<Row>  $batch
     * @return array{staged:int, conflicts:list<string>, pending:list<Row>}
     */
    public function compare(Connection $destination, array $table, array $batch): array
    {
        if ($batch === []) {
            return ['staged' => 0, 'conflicts' => [], 'pending' => []];
        }
        $existing = $destination->table($table['target'])->useWritePdo()->whereIn('id', array_column($batch, 'id'))
            ->orWhereIn('identity_hash', array_column($batch, 'identity_hash'))->get();
        $byId = $existing->keyBy('id');
        $byIdentity = $existing->keyBy('identity_hash');
        $staged = 0;
        $conflicts = [];
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

        return ['staged' => $staged, 'conflicts' => $conflicts, 'pending' => $pending];
    }

    private function tableName(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}(?:\.[A-Za-z_][A-Za-z0-9_]{0,62})?\z/', $value) !== 1) {
            throw new InvalidTranslationInputException('Legacy translation table names must be plain or schema-qualified identifiers.');
        }

        return $value;
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

            $compared = $this->compare($destination, $table, $batch);
            $staged += $compared['staged'];
            $conflicts = [...$conflicts, ...$compared['conflicts']];

            if ($write) {
                if ($conflicts !== []) {
                    throw new TranslationsException('Translation catalog changed while staging: '.$conflicts[0]);
                }
                $pending = $compared['pending'];
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
}
