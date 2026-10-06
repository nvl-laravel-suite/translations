<?php

declare(strict_types=1);

namespace Nvl\Translations\Exceptions;

use Exception;
use Nvl\Support\Contracts\RespondableException;
use Nvl\Support\Exceptions\ExceptionResponse;
use Nvl\Support\Traits\InteractsWithPackageFailure;
use Nvl\Translations\Enums\TranslationsResponseCode;

/**
 * Base exception for translation workspace failures.
 *
 * @api
 */
class TranslationsException extends Exception implements RespondableException
{
    use InteractsWithPackageFailure;

    /** Resolve the declared safe failure for this native hierarchy. */
    protected function exceptionResponse(): ExceptionResponse
    {
        return match (static::class) {
            InvalidTranslationInputException::class => new ExceptionResponse('translations', TranslationsResponseCode::InvalidTranslationInput, 422),
            StaleTranslationWorkspaceException::class => new ExceptionResponse('translations', TranslationsResponseCode::StaleTranslationWorkspace, 409),
            TranslationConflictException::class => new ExceptionResponse('translations', TranslationsResponseCode::TranslationSyncConflict, 409),
            TranslationWorkspaceLockedException::class => new ExceptionResponse('translations', TranslationsResponseCode::TranslationWorkspaceLocked, 423),
            default => new ExceptionResponse('translations', TranslationsResponseCode::OperationFailed),
        };
    }
}
