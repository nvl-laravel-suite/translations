<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Validates a literal native override allowlist without opening source-file paths. @internal */
final readonly class TranslationOverrideKeys
{
    /** Read current application configuration without retaining admission. */
    public function __construct(private Repository $configuration) {}

    /** Return the complete validated literal key allowlist.
     * @return list<string>
     */
    public function all(): array
    {
        $configured = $this->configuration->get('nvl-translations.ownership.keys', []);
        if (! is_array($configured) || ! array_is_list($configured)) {
            throw new OwnershipConfigurationInvalid('Override keys require a literal list.');
        }
        $keys = [];
        foreach ($configured as $key) {
            if (! is_string($key) || $key === '' || strlen($key) > 191 || strpbrk($key, '/\\*') !== false) {
                throw new OwnershipConfigurationInvalid('Override keys require bounded literal keys without file paths or wildcards.');
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /** Require an explicitly allowed bounded source key and locale. */
    public function assertAllowed(string $key, string $locale): void
    {
        if (! in_array($key, $this->all(), true) || strlen($locale) > 35 || preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D', $locale) !== 1) {
            throw new OwnershipViolation('The translation override key or locale is not allowed.');
        }
    }
}
