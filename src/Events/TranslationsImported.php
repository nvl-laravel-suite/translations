<?php

declare(strict_types=1);

namespace Nvl\Translations\Events;

use Nvl\Support\Contracts\DomainEvent;

/**
 * Reports a completed authoritative-file import.
 *
 * @api
 */
final class TranslationsImported implements DomainEvent
{
    /**
     * @param  array{scopes:int,files:int,entries:int,created:int,updated:int,preserved:int,conflicts:int,missing:int,warnings:list<string>}  $result
     */
    public function __construct(public readonly array $result, public readonly int $schemaVersion = 1) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
