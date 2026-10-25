<?php

declare(strict_types=1);

namespace Nvl\Translations\Data;

use Spatie\LaravelData\Data;

/**
 * One immutable read-only Translations installation diagnostic.
 */
final class TranslationsDoctorCheckData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly bool $passed,
        public readonly string $message,
    ) {}
}
