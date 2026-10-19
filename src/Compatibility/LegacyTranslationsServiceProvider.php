<?php

declare(strict_types=1);

namespace Nvl\Translations\Compatibility;

use Illuminate\Support\ServiceProvider;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Translations\Actions\ExportTenantTranslationsAction;
use Nvl\Translations\Contracts\ExportTenantTranslationsContract;
use Nvl\Translations\Contracts\TenantTranslationRepository;
use Nvl\Translations\Services\DatabaseTenantTranslationRepository;
use Nvl\Translations\Tenancy\TranslationsResourceRegistrar;

/** Contains temporary legacy registrations; it never grants native owner authority. @internal */
final class LegacyTranslationsServiceProvider extends ServiceProvider
{
    /** Register legacy metadata and lazy APIs behind their native-state guards. */
    public function register(): void
    {
        (new TranslationsResourceRegistrar)->register($this->app->make(TenantResourceRegistry::class));
        $this->app->bindIf(ExportTenantTranslationsContract::class, ExportTenantTranslationsAction::class);
        $this->app->scopedIf(TenantTranslationRepository::class, DatabaseTenantTranslationRepository::class);
        $this->app->booted(function (): void {
            if ($this->app->bound(TenantAdoptionRegistry::class)) {
                (new TranslationsResourceRegistrar)->register($this->app->make(TenantResourceRegistry::class), $this->app->make(TenantAdoptionRegistry::class));
            }
        });
    }
}
