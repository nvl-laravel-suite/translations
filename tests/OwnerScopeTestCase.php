<?php

declare(strict_types=1);

namespace Nvl\Translations\Tests;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\DB;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Filterable\Providers\FilterableServiceProvider;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\OwnershipTestCase;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Translations\Providers\TranslationsServiceProvider;
use Nvl\Translations\Tests\Fixtures\ConfiguredOverride;

/** Uses native owners and a real nonempty historical copy without outer test transactions. */
abstract class OwnerScopeTestCase extends OwnershipTestCase
{
    public const string Tenant = 'c587b7fb-a18e-47a8-9d16-78c983e49101';

    public const string Override = 'c587b7fb-a18e-47a8-9d16-78c983e49102';

    public OwnershipAdoptionPlan $adoptionPlan;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LocaleServiceProvider::class, DataServiceProvider::class, FilterableServiceProvider::class, TranslationsServiceProvider::class];
    }

    /** Select one actual configured override class and literal native keys. */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set(['nvl-translations.models.override' => ConfiguredOverride::class, 'nvl-translations.ownership.scoped' => true, 'nvl-translations.ownership.keys' => ['headline', 'legacy.copy'], 'nvl-translations.migrations.enabled' => false]);
    }

    /** Rehearse the actual package adoption before admitting either owner. */
    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $path) {
            (require $path)->up();
        }
        (require __DIR__.'/../database/tenancy-migrations/2026_09_16_180002_nvl_translations_create_tenant_translation_overrides.php')->up();
        DB::table('nvl_translations_tenant_overrides')->insert(['id' => self::Override, 'tenant_id' => self::Tenant, 'key' => 'legacy.copy', 'locale' => 'en', 'value' => 'preserved', 'revision' => 9, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00']);
        (require __DIR__.'/../../ownership/database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
        $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
        {
            public function authorize(OwnershipOperation $operation): void {}
        });
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            private bool $enabled = true;

            public function activate(array $payload): void
            {
                $this->enabled = true;
            }

            public function deactivate(): void
            {
                $this->enabled = false;
            }

            public function active(): bool
            {
                return $this->enabled;
            }

            public function data(): array
            {
                return [];
            }
        });
        $operation = new OwnershipOperation('translations-adopt', 'operator', '1');
        $owner = $this->app->make(OwnerIdentityResolver::class)->reference(Company::findOrFail(7));
        $coordinator = $this->app->make(OwnershipAdoptionCoordinator::class);
        $this->adoptionPlan = $coordinator->prepare(['translations'], [new OwnershipMapping('translations.overrides', self::Override, $owner, legacyTenantId: self::Tenant)], $operation);
        while (! $coordinator->backfill($this->adoptionPlan, 100, $operation)) {
        }
        $coordinator->activate($this->adoptionPlan, $operation);
        $this->app->make(MaintenanceMode::class)->deactivate();
        $this->allowOwners();
    }
}
