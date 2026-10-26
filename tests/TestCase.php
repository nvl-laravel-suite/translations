<?php

declare(strict_types=1);

namespace Nvl\Translations\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Providers\TranslationsServiceProvider;
use Nvl\Translations\Tests\Fixtures\TranslationApiAuthorizationFake;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Translations and its runtime dependencies in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        Relation::morphMap([], false);
        parent::setUp();
        $this->app->instance(TranslationsAuthorization::class, new TranslationApiAuthorizationFake);
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LocaleServiceProvider::class,
            DataServiceProvider::class,
            SupportServiceProvider::class,
            TranslationsServiceProvider::class,
        ];
    }
}
