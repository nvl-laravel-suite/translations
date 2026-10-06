<?php

declare(strict_types=1);

namespace Nvl\Translations\Exceptions;

/**
 * @api

 * Raised when a public translation operation receives an unsafe or unsupported value.
 */
final class InvalidTranslationInputException extends TranslationsException {}
