<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

/**
 * Defines the consumer-facing ExportTranslationsAction workflow.
 *
 * @api
 */
interface ExportTranslationsContract
{
    /**
     * @param  list<string>  $scopeTokens
     * @param  list<string>|null  $locales
     * @return array{scopes:int,locales:int,files:int,deleted:int,target:string}
     */
    public function execute(
        array $scopeTokens = [],
        ?array $locales = null,
        string $format = 'both',
        string $target = 'source',
        bool $prune = false,
        bool $dryRun = false,
    ): array;
}
