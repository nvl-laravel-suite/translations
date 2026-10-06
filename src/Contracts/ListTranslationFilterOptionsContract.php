<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

/**
 * Defines the consumer-facing ListTranslationFilterOptionsAction workflow.
 *
 * @api
 */
interface ListTranslationFilterOptionsContract
{
    /**
     * Return sorted option values for the translation index.
     *
     * @return array{scopeTypes: list<string>, scopeNames: list<string>, locales: list<string>, groups: list<string>} Translation filter options
     */
    public function execute(): array;
}
