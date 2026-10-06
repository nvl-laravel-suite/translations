<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

use Nvl\Translations\Data\TenantTranslationExportData;

/**
 * Defines the consumer-facing ExportTenantTranslationsAction workflow.
 *
 * @api
 */
interface ExportTenantTranslationsContract
{
    /**
     * Execute the execute workflow.
     */
    public function execute(string $disk): TenantTranslationExportData;
}
