<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Nvl\Filterable\Data\FilterSet;
use Nvl\Translations\Models\TranslationEntry;

/**
 * Defines the consumer-facing ListTranslationEntriesAction workflow.
 *
 * @api
 */
interface ListTranslationEntriesContract
{
    /**
     * Execute listing query.
     *
     * @param  int  $perPage  Requested page size
     * @return LengthAwarePaginator<int, TranslationEntry>
     */
    public function execute(int $perPage = 25, ?FilterSet $filters = null): LengthAwarePaginator;
}
