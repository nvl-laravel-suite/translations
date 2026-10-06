<?php

declare(strict_types=1);

namespace Nvl\Translations\Exceptions;

/**
 * @api

 * Raised when both the authoritative file and editable database value changed.
 */
final class TranslationConflictException extends TranslationsException
{
    /**
     * Build an exception for one conflicting catalog identity.
     */
    public static function forIdentity(string $scope, string $identity): self
    {
        return new self("Translation sync conflict for [{$scope}:{$identity}].");
    }
}
