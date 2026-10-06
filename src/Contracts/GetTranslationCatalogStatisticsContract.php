<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

use Nvl\Filterable\Data\FilterSet;
use Nvl\Translations\Data\TranslationCatalogStatisticsData;

/**
 * Defines the consumer-facing GetTranslationCatalogStatisticsAction workflow.
 *
 * @api
 */
interface GetTranslationCatalogStatisticsContract
{
    /**
     * Execute the authorized catalog statistics query.
     */
    public function execute(?FilterSet $filters = null): TranslationCatalogStatisticsData;
}
