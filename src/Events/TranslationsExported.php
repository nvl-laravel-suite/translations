<?php

declare(strict_types=1);

namespace Nvl\Translations\Events;

use Nvl\Support\Contracts\DomainEvent;

/**
 * Reports a completed database-to-file translation synchronization.
 *
 * @api
 */
final class TranslationsExported implements DomainEvent
{
    /**
     * @param  array{scopes:int,locales:int,files:int,deleted:int,target:string}  $result
     */
    public function __construct(public readonly array $result, public readonly int $schemaVersion = 1) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
