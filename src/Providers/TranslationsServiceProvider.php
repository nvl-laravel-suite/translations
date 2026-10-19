<?php

declare(strict_types=1);

namespace Nvl\Translations\Providers;

use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Nvl\Translations\Actions\Entries\GetTranslationCatalogStatisticsAction;
use Nvl\Translations\Actions\Entries\ListTranslationEntriesAction;
use Nvl\Translations\Actions\Entries\ListTranslationFilterOptionsAction;
use Nvl\Translations\Actions\Entries\UpdateTranslationEntryAction;
use Nvl\Translations\Actions\ExportTranslationOverridesAction;
use Nvl\Translations\Actions\Sync\ExportTranslationsAction;
use Nvl\Translations\Actions\Sync\ImportTranslationsAction;
use Nvl\Translations\Actions\Sync\ListUnusedTranslationsAction;
use Nvl\Translations\Actions\Sync\ScanTranslationsAction;
use Nvl\Translations\Compatibility\LegacyTranslationsServiceProvider;
use Nvl\Translations\Console\Commands\TranslationsDoctorCommand;
use Nvl\Translations\Console\Commands\TranslationsExportCommand;
use Nvl\Translations\Console\Commands\TranslationsImportCommand;
use Nvl\Translations\Console\Commands\TranslationsPruneCommand;
use Nvl\Translations\Console\Commands\TranslationsScanCommand;
use Nvl\Translations\Console\Commands\TranslationsStatusCommand;
use Nvl\Translations\Console\Commands\TranslationsUnusedCommand;
use Nvl\Translations\Contracts\ExportTranslationOverridesContract;
use Nvl\Translations\Contracts\ExportTranslationsContract;
use Nvl\Translations\Contracts\GetTranslationCatalogStatisticsContract;
use Nvl\Translations\Contracts\ImportTranslationsContract;
use Nvl\Translations\Contracts\ListTranslationEntriesContract;
use Nvl\Translations\Contracts\ListTranslationFilterOptionsContract;
use Nvl\Translations\Contracts\ListUnusedTranslationsContract;
use Nvl\Translations\Contracts\ScanTranslationsContract;
use Nvl\Translations\Contracts\TranslationOverrideRepository;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Contracts\UpdateTranslationEntryContract;
use Nvl\Translations\Ownership\TranslationsAdoptionAdapter;
use Nvl\Translations\Ownership\TranslationsResourceRegistrar;
use Nvl\Translations\Services\ConfiguredTranslationsAuthorization;
use Nvl\Translations\Services\DatabaseTranslationOverrideRepository;
use Nvl\Translations\Services\TranslationOverrideModels;
use Nvl\Translations\Services\TranslationsDoctor;

/**
 * Registers the translation workspace package and its optional management API.
 */
final class TranslationsServiceProvider extends ServiceProvider implements OwnershipIntegratedProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    /**
     * Boot the application events.
     */
    public function boot(TypeScriptSourceRegistry $typeScriptSources): void
    {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/translations');

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
        }

        $this->registerTranslations();
        $this->registerConfig();
        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'translations-migrations');
        if ((bool) config('nvl-translations.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }
        if ((bool) config('nvl-translations.routes.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        }

        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'translations-skills');
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->bindIf(GetTranslationCatalogStatisticsContract::class, GetTranslationCatalogStatisticsAction::class);
        $this->app->bindIf(ListTranslationEntriesContract::class, ListTranslationEntriesAction::class);
        $this->app->bindIf(ListTranslationFilterOptionsContract::class, ListTranslationFilterOptionsAction::class);
        $this->app->bindIf(ExportTranslationsContract::class, ExportTranslationsAction::class);
        $this->app->bindIf(ListUnusedTranslationsContract::class, ListUnusedTranslationsAction::class);

        PackageDoctorContributor::register($this->app, 'nvl/translations', fn (): array => $this->app->make(TranslationsDoctor::class)->inspect());

        $this->app->register(SupportServiceProvider::class);
        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-translations.php', 'translations');
        $this->app->register(LegacyTranslationsServiceProvider::class, true);
        $this->app->singletonIf(TranslationOverrideModels::class);
        $this->app->scopedIf(TranslationOverrideRepository::class, DatabaseTranslationOverrideRepository::class);
        $this->app->bindIf(ExportTranslationOverridesContract::class, ExportTranslationOverridesAction::class);
        $this->app->booted(function (): void {
            $owned = config('nvl-ownership.enabled') === true && config('nvl-translations.ownership.scoped') === true;
            $this->app->make(TranslationsResourceRegistrar::class)->register($this->app->make(OwnershipResourceRegistry::class), $owned);
            if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
                $this->app->make(OwnershipAdoptionRegistry::class)->register('translations', TranslationsAdoptionAdapter::class);
            }
        });

        $this->app->bindIf(
            UpdateTranslationEntryContract::class,
            UpdateTranslationEntryAction::class
        );
        $this->app->bindIf(
            ImportTranslationsContract::class,
            ImportTranslationsAction::class
        );
        $this->app->bindIf(
            ScanTranslationsContract::class,
            ScanTranslationsAction::class
        );
        $this->app->bindIf(
            TranslationsAuthorization::class,
            ConfiguredTranslationsAuthorization::class,
        );
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            TranslationsImportCommand::class,
            TranslationsExportCommand::class,
            TranslationsScanCommand::class,
            TranslationsUnusedCommand::class,
            TranslationsStatusCommand::class,
            TranslationsPruneCommand::class,
            TranslationsDoctorCommand::class,
        ]);
    }

    /**
     * Register translations.
     */
    protected function registerTranslations(): void
    {
        $langPath = __DIR__.'/../../lang';

        $this->app->make(GlobalNames::class)->translations('translations', $langPath, $this->app->make('translation.loader'));
        $this->publishes([
            $langPath => lang_path('vendor/nvl-translations'),
        ], 'translations-translations');
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([
            __DIR__.'/../../config/nvl-translations.php' => config_path('nvl-translations.php'),
        ], 'translations-config');
    }
}
