<?php

declare(strict_types=1);

namespace Nvl\Translations\Events;

use Nvl\Support\Contracts\DomainEvent;

/**
 * Reports a committed manual translation workspace edit.
 *
 * @api
 */
final class TranslationEntryUpdated implements DomainEvent
{
    /**
     * Create an immutable entry-update event.
     *
     * @param  array<string,mixed>  $entry
     */
    public function __construct(public readonly array $entry, public readonly int $schemaVersion = 1) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
