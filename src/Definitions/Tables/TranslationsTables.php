<?php

declare(strict_types=1);

namespace Nvl\Translations\Definitions\Tables;

use Nvl\Support\Config\PackageStorage;

/**
 * Database table names for the Translations module.
 */
final class TranslationsTables
{
    public const string Entries = 'nvl_translations_entries';

    public const string ScanRuns = 'nvl_translations_scan_runs';

    public const string Usages = 'nvl_translations_usages';

    public const string TenantOverrides = 'nvl_translations_tenant_overrides';

    public const string TRANSLATION_ENTRIES = self::Entries;

    public const string TRANSLATION_SCAN_RUNS = self::ScanRuns;

    public const string TRANSLATION_USAGES = self::Usages;

    /** Return one configured logical or historical package table. */
    public static function get(string $key): string
    {
        return PackageStorage::resolveTable('translations', $key);
    }

    private function __construct() {}
}
