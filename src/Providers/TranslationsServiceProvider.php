<?php

declare(strict_types=1);

namespace Nvl\Translations\Providers;

use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Providers\TenantServiceProvider;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Translations\Actions\Entries\UpdateTranslationEntryAction;
use Nvl\Translations\Actions\Sync\ImportTranslationsAction;
use Nvl\Translations\Actions\Sync\ScanTranslationsAction;
use Nvl\Translations\Console\Commands\TranslationsDoctorCommand;
use Nvl\Translations\Console\Commands\TranslationsExportCommand;
use Nvl\Translations\Console\Commands\TranslationsImportCommand;
use Nvl\Translations\Console\Commands\TranslationsPruneCommand;
use Nvl\Translations\Console\Commands\TranslationsScanCommand;
use Nvl\Translations\Console\Commands\TranslationsStatusCommand;
use Nvl\Translations\Console\Commands\TranslationsUnusedCommand;
use Nvl\Translations\Contracts\ImportTranslationsContract;
use Nvl\Translations\Contracts\ScanTranslationsContract;
use Nvl\Translations\Contracts\TenantTranslationRepository;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Contracts\UpdateTranslationEntryContract;
use Nvl\Translations\Services\ConfiguredTranslationsAuthorization;
use Nvl\Translations\Services\DatabaseTenantTranslationRepository;
use Nvl\Translations\Services\TranslationsDoctor;
use Nvl\Translations\Tenancy\TranslationsResourceRegistrar;

/**
 * Registers the translation workspace package and its optional management API.
 */
final class TranslationsServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;

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
        if ((bool) config('translations.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }
        if ((bool) config('translations.routes.enabled', false)) {
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
        PackageDoctorContributor::register($this->app, 'nvl/translations', fn (): array => $this->app->make(TranslationsDoctor::class)->inspect());

        $this->app->register(TenantServiceProvider::class);
        $this->mergePackageConfiguration(__DIR__.'/../../config/translations.php', 'translations');
        (new TranslationsResourceRegistrar)->register($this->app->make(TenantResourceRegistry::class));
        $this->app->booted(function (): void {
            if ($this->app->bound(TenantAdoptionRegistry::class)) {
                (new TranslationsResourceRegistrar)->register($this->app->make(TenantResourceRegistry::class), $this->app->make(TenantAdoptionRegistry::class));
            }
        });
        $this->app->scopedIf(TenantTranslationRepository::class, DatabaseTenantTranslationRepository::class);

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

        $this->loadTranslationsFrom($langPath, 'translations');
        $this->loadJsonTranslationsFrom($langPath);
        $this->publishes([
            $langPath => lang_path('vendor/translations'),
        ], 'translations-translations');
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([
            __DIR__.'/../../config/translations.php' => config_path('translations.php'),
        ], 'translations-config');
    }
}
