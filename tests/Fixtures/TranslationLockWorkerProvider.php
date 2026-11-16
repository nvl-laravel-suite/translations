<?php

declare(strict_types=1);

namespace Nvl\Translations\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Nvl\Translations\Contracts\TranslationsAuthorization;

/** Rebuilds the parent test's committed PostgreSQL source, Redis lock store and authorization in each worker. */
final class TranslationLockWorkerProvider extends ServiceProvider
{
    /** @var array<string, mixed> */
    public static array $configuration = [];

    /** Configure the worker before the package providers register their defaults. */
    public function register(): void
    {
        $this->app->make('config')->set([
            'app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            ...self::$configuration,
        ]);
        $this->app->instance(TranslationsAuthorization::class, new TranslationApiAuthorizationFake);
    }
}
