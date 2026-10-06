<?php

declare(strict_types=1);

namespace Nvl\Translations\Events;

use Carbon\CarbonImmutable;
use Nvl\Support\Contracts\DomainEvent;

/**
 * Reports a completed source-code translation usage scan.
 *
 * @api
 */
final class TranslationsScanned implements DomainEvent
{
    /**
     * @param  array{files:int,hits:int,scanned_at:CarbonImmutable}  $result
     */
    public function __construct(public readonly array $result, public readonly int $schemaVersion = 1) {}

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
