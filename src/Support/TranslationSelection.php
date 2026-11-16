<?php

declare(strict_types=1);

namespace Nvl\Translations\Support;

use Nvl\Translations\Enums\TranslationFormat;
use Nvl\Translations\Exceptions\InvalidTranslationInputException;
use Nvl\Translations\Services\TranslationPathGuard;

/**
 * Selects catalog files within configured directory scopes.
 *
 * @api
 */
final readonly class TranslationSelection
{
    /** @var list<string> */
    private array $groupPrefixes;

    /**
     * PHP prefixes match a complete group or its slash-delimited descendants.
     *
     * @param  list<string>  $scopeTokens
     * @param  array<array-key, mixed>  $groupPrefixes
     */
    public function __construct(public array $scopeTokens = [], private string $format = 'both', array $groupPrefixes = [])
    {
        if (! in_array($format, ['php', 'json', 'both'], true)) {
            throw new InvalidTranslationInputException("Invalid translation format [{$format}]. Expected php, json, or both.");
        }
        if (! array_is_list($groupPrefixes) || ($groupPrefixes !== [] && $format !== 'php')) {
            throw new InvalidTranslationInputException('Group prefixes require a list and the php translation format.');
        }

        $paths = new TranslationPathGuard;
        foreach ($groupPrefixes as $prefix) {
            if (! is_string($prefix) || $paths->group($prefix) !== $prefix || in_array('.', explode('/', $prefix), true)) {
                throw new InvalidTranslationInputException('A group prefix must be a canonical PHP translation group.');
            }
        }

        $this->groupPrefixes = array_values(array_unique($groupPrefixes));
    }

    /**
     * Accept the legacy scope-list and format arguments at existing call sites.
     *
     * @internal
     *
     * @param  list<string>|self  $scopeTokens
     */
    public static function resolve(array|self $scopeTokens, string $format): self
    {
        if (is_array($scopeTokens)) {
            return new self($scopeTokens, $format);
        }
        if ($format !== 'both') {
            throw new InvalidTranslationInputException('Specify the format on the translation selection.');
        }

        return $scopeTokens;
    }

    /** @internal Check whether an existing entry falls within the selected format and group. */
    public function includes(string $format, string $group): bool
    {
        return match ($format) {
            'php' => $this->includesFormat(TranslationFormat::Php) && $this->includesGroup($group),
            'json' => $this->includesFormat(TranslationFormat::Json),
            default => false,
        };
    }

    /** @internal Check whether the selected format includes a file type. */
    public function includesFormat(TranslationFormat $format): bool
    {
        return $this->format === 'both' || $this->format === $format->value;
    }

    /** @internal Determine whether locale discovery needs to restrict PHP groups. */
    public function hasGroupPrefixes(): bool
    {
        return $this->groupPrefixes !== [];
    }

    /** @internal Check a case-sensitive PHP group or relative group path. */
    public function includesGroup(string $group): bool
    {
        if ($this->groupPrefixes === []) {
            return true;
        }

        foreach ($this->groupPrefixes as $prefix) {
            if ($group === $prefix || str_starts_with($group, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
