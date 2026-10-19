<?php

declare(strict_types=1);

namespace Nvl\Translations\Contracts;

/** Manages literal copy overrides within the currently admitted owner. @api */
interface TranslationOverrideRepository
{
    /** Read one allowed override from the current owner. */
    public function get(string $key, string $locale): ?string;

    /** Write one expected revision without changing source files or another owner's copy. */
    public function set(string $key, string $locale, string $value, int $expectedRevision): void;

    /** Delete only the current owner's expected revision. */
    public function forget(string $key, string $locale, int $expectedRevision): void;
}
