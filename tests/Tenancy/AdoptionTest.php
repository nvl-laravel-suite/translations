<?php

declare(strict_types=1);

use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Translations\Providers\TranslationsServiceProvider;
use Nvl\Translations\Tenancy\TranslationsAdoptionAdapter;

beforeEach(function (): void {
    app()->register(TenancyServiceProvider::class);
    app()->register(TranslationsServiceProvider::class, true);
});

it('registers the package-owned translations adopter', function (): void {
    expect(app(TenantAdoptionRegistry::class)->all()['translations'] ?? null)->toBe(TranslationsAdoptionAdapter::class);
});
