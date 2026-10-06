<?php

declare(strict_types=1);

namespace Nvl\Translations\Exceptions;

/**
 * @api

 * Raised when another synchronization process owns the workspace lock.
 */
final class TranslationWorkspaceLockedException extends TranslationsException
{
    /**
     * Build a lock-contention exception for one workspace operation.
     */
    public static function forOperation(string $operation): self
    {
        return new self(
            "The translation workspace is already running [{$operation}].",
        );
    }
}
