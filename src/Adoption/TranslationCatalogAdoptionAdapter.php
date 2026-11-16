<?php

declare(strict_types=1);

namespace Nvl\Translations\Adoption;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Nvl\Support\Adoption\AdoptionAdapter;
use Nvl\Support\Adoption\AdoptionBatch;
use Nvl\Support\Adoption\AdoptionPlan;
use Nvl\Support\Adoption\AdoptionStep;
use Nvl\Support\Adoption\AdoptionVerification;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Services\SourceTranslationWorkspace;
use Nvl\Translations\Services\TranslationProcessLock;
use Nvl\Translations\Support\LegacyTranslationCatalogReview;
use Nvl\Translations\Support\LegacyTranslationCatalogRows;
use stdClass;

/**
 * Adopts a standalone legacy catalog through nvl:adopt: entries first, then usages, in checkpointed keyset batches.
 *
 * Options match TranslationCatalogAdopter: connection, entries_table, usages_table and the reviewed source_values
 * baselines. Each batch transaction and each verify or activate transaction, commits included, run under the workspace
 * process lock, which is always taken before the run row.
 *
 * @internal
 *
 * @phpstan-import-type Options from LegacyTranslationCatalogReview
 */
final class TranslationCatalogAdoptionAdapter extends AdoptionAdapter
{
    private const string Version = '1';

    private const int CheckpointPage = 500;

    /** @var Options */
    private readonly array $catalogOptions;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        Authenticatable|SystemActor $actor,
        array $options,
        private readonly SourceTranslationWorkspace $workspace,
        private readonly TranslationProcessLock $lock,
        private readonly LegacyTranslationCatalogReview $catalog,
        private readonly LegacyTranslationCatalogRows $rows,
    ) {
        parent::__construct($actor, $options);
        $this->catalogOptions = $catalog->options($options);
    }

    public function plan(): AdoptionPlan
    {
        $review = $this->catalog->review($this->catalogOptions);
        $origin = $this->catalog->origin($this->catalogOptions);
        $tables = $this->catalog->tables($this->catalogOptions);

        return new AdoptionPlan(
            package: 'translations',
            adapterVersion: self::Version,
            fingerprint: AdoptionPlan::digest(['version' => self::Version, 'review' => $review['fingerprint'], 'options' => $this->catalogOptions]),
            source: ['connection' => $origin->getName(), 'database' => $origin->getDatabaseName(), 'entries' => $tables['entries']['source'], 'usages' => $tables['usages']['source']],
            destination: ['entries' => $tables['entries']['target'], 'usages' => $tables['usages']['target']],
            optionsDigest: AdoptionPlan::digest($this->catalogOptions),
            steps: [new AdoptionStep('entries'), new AdoptionStep('usages', ['entries'])],
            expected: ['entries' => $review['entries'], 'usages' => $review['usages']],
            collisions: $review['conflicts'],
        );
    }

    public function connection(): Connection
    {
        return $this->catalog->destination();
    }

    public function authorize(string $phase): void
    {
        $this->workspace->authorize($this->actor, TranslationsAbility::Synchronize);
    }

    public function nextBatch(AdoptionStep $step, ?array $cursor, int $limit): AdoptionBatch
    {
        $records = $step->page($this->source($step), $cursor, $limit + 1)->get()->all();
        $page = array_slice($records, 0, $limit);
        $last = $page === [] ? null : $page[array_key_last($page)];

        return new AdoptionBatch($step->id, $cursor, $last === null ? $cursor : $step->cursorFrom($last), $this->map($step, $page), count($records) <= $limit);
    }

    /**
     * Re-read the committed source prefix in keyset pages, map it as writeBatch() did and require every mapped row to be
     * staged unchanged. The prefix must still hold exactly the checkpoint's processed rows.
     */
    public function assertCheckpoint(AdoptionStep $step, ?array $cursor, int $processed): void
    {
        $destination = $this->connection();
        $table = $this->table($step->id);
        $seen = 0;
        $after = null;
        do {
            $page = $step->page($step->through($this->source($step), $cursor), $after, self::CheckpointPage)->get()->all();
            if ($page === []) {
                break;
            }
            $seen += count($page);
            $after = $step->cursorFrom($page[array_key_last($page)]);
            /** @var list<array{id:string, identity_hash:string, ...<string, bool|int|string|null>}> $rows */
            $rows = $this->map($step, $page);
            $compared = $this->catalog->compare($destination, $table, $rows);
            if ($compared['conflicts'] !== []) {
                throw new TranslationsException('Translation catalog drift behind the cursor: '.$compared['conflicts'][0]);
            }
            if ($compared['pending'] !== []) {
                throw new TranslationsException("Translation adoption step [{$step->id}] lost staged row [{$compared['pending'][0]['id']}] behind its checkpoint.");
            }
        } while (count($page) === self::CheckpointPage);

        if ($seen !== $processed) {
            throw new TranslationsException("Translation adoption step [{$step->id}] checkpoint counts {$processed} source rows, but its committed prefix holds {$seen}.");
        }
    }

    /**
     * Hold the workspace process lock around the whole batch transaction, commit included; it is released in a finally.
     */
    public function aroundBatch(Closure $batch): mixed
    {
        return $this->lock->execute('adopt', $this->connection(), static fn (): mixed => $batch());
    }

    /**
     * Insert rows that are not staged yet; refuse any staged row that differs.
     */
    public function writeBatch(AdoptionBatch $batch): ?string
    {
        $destination = $this->connection();
        $table = $this->table($batch->step);
        /** @var list<array{id:string, identity_hash:string, ...<string, bool|int|string|null>}> $rows */
        $rows = $batch->rows;
        $compared = $this->catalog->compare($destination, $table, $rows);
        if ($compared['conflicts'] !== []) {
            throw new TranslationsException('Translation catalog drift behind the cursor: '.$compared['conflicts'][0]);
        }
        if ($compared['pending'] !== []) {
            $destination->table($table['target'])->insert($compared['pending']);
        }

        return null;
    }

    /**
     * Take the workspace process lock before the coordinator locks the run row, the order every batch uses.
     */
    public function aroundVerification(Closure $operation): mixed
    {
        return $this->lock->execute('verify adoption', $this->connection(), static fn (): mixed => $operation());
    }

    /**
     * Scan under the workspace lock that aroundVerification() already holds.
     */
    public function verify(AdoptionPlan $plan): AdoptionVerification
    {
        $review = $this->catalog->review($this->catalogOptions);
        $missing = max(0, $review['entries'] - $review['staged_entries']) + max(0, $review['usages'] - $review['staged_usages']);

        return new AdoptionVerification(
            missing: $missing,
            extra: 0,
            changed: 0,
            digest: AdoptionPlan::digest([$review['fingerprint'], $review['staged_entries'], $review['staged_usages'], $review['conflicts']]),
            problems: $review['conflicts'],
        );
    }

    private function source(AdoptionStep $step): Builder
    {
        $table = $this->table($step->id);

        return $this->catalog->origin($this->catalogOptions)->table($table['source'])->useWritePdo()->select($table['columns']);
    }

    /**
     * Map source records exactly as they are staged.
     *
     * @param  array<int, stdClass>  $records
     * @return list<array<string, mixed>>
     */
    private function map(AdoptionStep $step, array $records): array
    {
        $baselines = $this->catalogOptions['source_values'];
        $rows = [];
        foreach ($records as $record) {
            $rows[] = $step->id === 'entries' ? $this->rows->entry($record, $baselines) : $this->rows->usage($record);
        }

        return $rows;
    }

    /**
     * @return array{source:string, target:string, columns:list<string>, native:list<string>}
     */
    private function table(string $step): array
    {
        return $this->catalog->tables($this->catalogOptions)[$step]
            ?? throw new TranslationsException("Unknown translation adoption step [{$step}].");
    }
}
