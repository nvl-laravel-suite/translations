<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipContextResolver;
use Nvl\Translations\Compatibility\LegacySourceTranslationWorkspace;

/** Keeps source-code catalog tooling outside owner execution and owner copy persistence. */
final readonly class SourceTranslationWorkspace
{
    /** Resolve current native state at each operation and preserve the disabled legacy workflow. */
    public function __construct(private OwnershipContextResolver $ownership, private LegacySourceTranslationWorkspace $legacy) {}

    /** Deny before catalog or filesystem access from any admitted owner or public site. */
    public function authorize(): void
    {
        $snapshot = $this->ownership->current()->snapshot();
        if ($snapshot->mode === OwnershipContextMode::Disabled) {
            $this->legacy->authorize();

            return;
        }
        if ($snapshot->publicSite !== null || ! in_array($snapshot->mode, [OwnershipContextMode::Unresolved, OwnershipContextMode::Central], true)) {
            throw new OwnershipViolation('Source translation tooling is unavailable inside owner execution.');
        }
    }
}
