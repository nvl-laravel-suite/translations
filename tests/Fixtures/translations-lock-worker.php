<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Nvl\Support\Adoption\AdoptionCoordinator;
use Nvl\Support\Adoption\AdoptionRegistry;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translations\Exceptions\TranslationWorkspaceLockedException;
use Nvl\Translations\Providers\TranslationsServiceProvider;
use Nvl\Translations\Services\TranslationCatalog;
use Nvl\Translations\Tests\Fixtures\TranslationLockWorkerProvider;
use Nvl\Translations\Tests\Fixtures\TranslationLockWorkers;
use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$source = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$options = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($source) || ($source['driver'] ?? null) !== 'pgsql'
    || ! is_array($options) || ! is_string($options['directory'] ?? null) || ! is_dir($options['directory'])
    || preg_match('/^nvl-translations-race-[a-f0-9]{16}$/D', basename($options['directory'])) !== 1
    || ! is_array($options['redis'] ?? null) || ! in_array($options['operation'] ?? null, ['apply', 'verify', 'activate', 'update'], true)) {
    throw new RuntimeException('Translation lock workers require the committed PostgreSQL source and barrier directory.');
}

// Keep the parent's connection name: adoption plans fingerprint it.
$connection = is_string($source['name'] ?? null) ? $source['name'] : 'pgsql';
unset($source['name']);
TranslationLockWorkerProvider::$configuration = [
    'database.default' => $connection,
    "database.connections.{$connection}" => $source,
    'nvl-translations.lock.wait_seconds' => (int) ($options['wait_seconds'] ?? 0),
    ...TranslationLockWorkers::lockConfiguration($options['redis']),
];
$app = Application::create(options: ['load_environment_variables' => false, 'extra' => [
    'dont-discover' => ['*'],
    'providers' => [TranslationLockWorkerProvider::class, LocaleServiceProvider::class, SupportServiceProvider::class, TranslationsServiceProvider::class],
]]);
try {
    $app->make(Kernel::class)->bootstrap();
    $ready = $options['directory'].'/ready';
    file_put_contents($ready.'.pending', '{}');
    rename($ready.'.pending', $ready);

    try {
        $result = match ($options['operation']) {
            'update' => app(TranslationCatalog::class)->update(SystemActor::Background, (string) $options['entry'], ['value' => 'Worker', 'expected_revision' => (int) $options['revision']])->value,
            default => (static function () use ($options): string {
                $coordinator = new AdoptionCoordinator(app(AdoptionRegistry::class)->make('translations', SystemActor::Background, (array) $options['adoption']));

                return match ($options['operation']) {
                    'apply' => $coordinator->apply((string) $options['fingerprint'], 1)->state,
                    'verify' => $coordinator->verify()->passed ? 'passed' : 'failed',
                    'activate' => $coordinator->activate((string) $options['fingerprint'])->state,
                };
            })(),
        };
        $outcome = ['status' => 'completed', 'result' => $result];
    } catch (TranslationWorkspaceLockedException $exception) {
        $outcome = ['status' => 'rejected', 'exception' => $exception::class];
    }

    fwrite(STDOUT, json_encode($outcome, JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(1);
}
