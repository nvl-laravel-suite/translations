<?php

declare(strict_types=1);

namespace Nvl\Translations\Providers;

use Illuminate\Support\ServiceProvider;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Nvl\Translations\Console\Commands\TranslationsDoctorCommand;
use Nvl\Translations\Console\Commands\TranslationsExportCommand;
use Nvl\Translations\Console\Commands\TranslationsImportCommand;
use Nvl\Translations\Console\Commands\TranslationsPruneCommand;
use Nvl\Translations\Console\Commands\TranslationsScanCommand;
use Nvl\Translations\Console\Commands\TranslationsStatusCommand;
use Nvl\Translations\Console\Commands\TranslationsUnusedCommand;
use Nvl\Translations\Contracts\TranslationOverrideRepository;
use Nvl\Translations\Contracts\TranslationsAuthorization;
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
    public function boot(): void
    {

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
        }

        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'nvl-translations-skills');
        $this->registerTranslations();
        $this->registerConfig();
        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'nvl-translations-migrations');
        if ((bool) config('nvl-translations.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {

        PackageDoctorContributor::register($this->app, 'nvl/translations', fn (): array => $this->app->make(TranslationsDoctor::class)->inspect());

        $this->app->register(SupportServiceProvider::class);
        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-translations.php', 'translations');
        $this->app->singletonIf(TranslationOverrideModels::class);
        $this->app->scopedIf(TranslationOverrideRepository::class, DatabaseTranslationOverrideRepository::class);
        $this->app->booted(function (): void {
            $owned = config('nvl-ownership.enabled') === true && config('nvl-translations.ownership.scoped') === true;
            $this->app->make(TranslationsResourceRegistrar::class)->register($this->app->make(OwnershipResourceRegistry::class), $owned);
            if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
                $this->app->make(OwnershipAdoptionRegistry::class)->register('translations', TranslationsAdoptionAdapter::class);
            }
        });

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
        ], 'nvl-translations-translations');
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([
            __DIR__.'/../../config/nvl-translations.php' => config_path('nvl-translations.php'),
        ], 'nvl-translations-config');
    }
}
