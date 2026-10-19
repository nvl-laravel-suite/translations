<?php

declare(strict_types=1);

namespace Nvl\Translations\Compatibility;

use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Enums\TenantContextMode;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

/**
 * Preserves the old source-workspace admission only while the native context is disabled.
 *
 * @internal
 */
final readonly class LegacySourceTranslationWorkspace
{
    /** Retain the selected legacy request context. */
    public function __construct(private TenantContext $context) {}

    /** Deny owner execution before global catalog or filesystem access. */
    public function authorize(): void
    {
        if (! in_array($this->context->snapshot()->mode, [TenantContextMode::Disabled, TenantContextMode::Platform], true)) {
            throw new TenantBoundaryViolation('Source translation tooling requires explicit platform context.');
        }
    }
}
