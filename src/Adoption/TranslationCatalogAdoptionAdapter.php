<?php

declare(strict_types=1);

namespace Nvl\Translations\Adoption;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
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

/**
 * Adopts a standalone legacy catalog through nvl:adopt: entries first, then usages, in checkpointed keyset batches.
 *
 * Options match TranslationCatalogAdopter: connection, entries_table, usages_table and the reviewed source_values
 * baselines. Each batch transaction, commit included, and each verification run under the workspace process lock.
 *
 * @internal
 *
 * @phpstan-import-type Options from LegacyTranslationCatalogReview
 */
final class TranslationCatalogAdoptionAdapter extends AdoptionAdapter
{
    private const string Version = '1';

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
        $table = $this->table($step->id);
        $query = $this->catalog->origin($this->catalogOptions)->table($table['source'])->useWritePdo()->select($table['columns']);
        $records = $step->page($query, $cursor, $limit + 1)->get()->all();
        $page = array_slice($records, 0, $limit);
        $baselines = $this->catalogOptions['source_values'];
        $rows = [];
        $last = null;
        foreach ($page as $record) {
            $rows[] = $step->id === 'entries' ? $this->rows->entry($record, $baselines) : $this->rows->usage($record);
            $last = $record;
        }

        return new AdoptionBatch($step->id, $cursor, $last === null ? $cursor : $step->cursorFrom($last), $rows, count($records) <= $limit);
    }

    /**
     * Hold the workspace process lock around the whole batch transaction, commit included; it is released in a finally.
     */
    public function aroundBatch(Closure $batch): mixed
    {
        return $this->lock->execute('adopt', static fn (): mixed => $batch());
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

    public function verify(AdoptionPlan $plan): AdoptionVerification
    {
        $review = $this->lock->execute('verify adoption', fn (): array => $this->catalog->review($this->catalogOptions));
        $missing = max(0, $review['entries'] - $review['staged_entries']) + max(0, $review['usages'] - $review['staged_usages']);

        return new AdoptionVerification(
            missing: $missing,
            extra: 0,
            changed: 0,
            digest: AdoptionPlan::digest([$review['fingerprint'], $review['staged_entries'], $review['staged_usages'], $review['conflicts']]),
            problems: $review['conflicts'],
        );
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
