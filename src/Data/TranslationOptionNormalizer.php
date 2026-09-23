<?php

declare(strict_types=1);

namespace Nvl\Translations\Data;

/** Normalizes validated comma-separated or list translation options. */
final class TranslationOptionNormalizer
{
    /**
     * @param  array<array-key, mixed>|string|null  $input
     * @return list<string>|null
     */
    public static function list(string|array|null $input): ?array
    {
        if ($input === null || $input === '') {
            return null;
        }

        $values = is_string($input) ? explode(',', $input) : $input;
        $normalized = array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $values),
            static fn (string $value): bool => $value !== '',
        ));

        return $normalized === [] ? null : $normalized;
    }
}
