<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

use Carbon\CarbonImmutable;

/**
 * Defines the consumer-facing ListUnusedTranslationsAction workflow.
 *
 * @api
 */
interface ListUnusedTranslationsContract
{
    /**
     * @param  list<string>  $scopeTokens
     * @return array{scanned_at:CarbonImmutable|null,total:int,rows:list<array{id:string,scope_type:string,scope_name:string,locale:string,format:string,group:string|null,key:string,full_key:string}>}
     */
    public function execute(array $scopeTokens = [], int $days = 0): array;
}
