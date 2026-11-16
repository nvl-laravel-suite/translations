<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Exceptions\TranslationsException;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Support\LegacyTranslationCatalogReview;
use Nvl\Translations\Support\LegacyTranslationCatalogRows;

/**
 * Stages an existing standalone catalog for an explicit host cutover.
 *
 * @api
 *
 * @phpstan-import-type Options from LegacyTranslationCatalogReview
 * @phpstan-import-type Review from LegacyTranslationCatalogReview
 */
final readonly class TranslationCatalogAdopter
{
    private LegacyTranslationCatalogReview $catalog;

    /** Reuse the permission and lock that protect the source catalog workspace. */
    public function __construct(
        DatabaseManager $database,
        private SourceTranslationWorkspace $workspace,
        private TranslationProcessLock $lock,
        LegacyTranslationCatalogRows $rows,
    ) {
        $this->catalog = new LegacyTranslationCatalogReview($database, $rows);
    }

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

        return $this->catalog->review($this->catalog->options($source));
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
        $options = $this->catalog->options($source);
        $this->requireCommittedCatalog();

        return $this->lock->execute('adopt', $this->catalog->destination(), function () use ($fingerprint, $options): array {
            $review = $this->catalog->review($options);
            $this->assertReviewed($review, $fingerprint);

            if ($review['staged_entries'] !== $review['entries'] || $review['staged_usages'] !== $review['usages']) {
                $this->catalog->review($options, write: true);
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
        $options = $this->catalog->options($source);
        $this->requireCommittedCatalog();

        return $this->lock->execute('verify adoption', $this->catalog->destination(), fn (): array => $this->reconcile($options, $fingerprint));
    }

    private function requireCommittedCatalog(): void
    {
        if ((new TranslationEntry)->getConnection()->transactionLevel() !== 0) {
            throw new TranslationsException('Translation catalog adoption requires a committed catalog outside existing transactions.');
        }
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
        $review = $this->catalog->review($options);
        $this->assertReviewed($review, $fingerprint);
        if ($review['staged_entries'] !== $review['entries'] || $review['staged_usages'] !== $review['usages']) {
            throw new TranslationsException('The translation catalog is not fully staged. Resume the reviewed adoption.');
        }

        return $review;
    }
}
