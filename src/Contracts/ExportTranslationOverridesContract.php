<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

use Nvl\Translations\Data\TranslationOverrideExportData;

/** Exports only the current owner's private literal overrides. @api */
interface ExportTranslationOverridesContract
{
    /** Write a private artifact after current canonical owner admission. */
    public function execute(string $disk): TranslationOverrideExportData;
}
