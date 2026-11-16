<?php

declare(strict_types=1);

namespace Nvl\Translations\Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Nvl\Support\Testing\TestServiceHosts;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs translation workspace operations in separate PHP processes that share the parent's committed PostgreSQL database
 * and a Redis workspace lock store.
 */
final class TranslationLockWorkers
{
    /**
     * Point the workspace lock at a Redis store isolated by a random prefix, and return that worker configuration.
     *
     * @return array{host: string, port: int, prefix: string}|null Null when no dedicated PostgreSQL and Redis are present
     */
    public static function configure(): ?array
    {
        $port = getenv('NVL_OWNERSHIP_TEST_REDIS_PORT');
        if (getenv('NVL_OWNERSHIP_TEST_DRIVER') !== 'pgsql' || DB::connection()->getDriverName() !== 'pgsql'
            || ! extension_loaded('redis') || ! is_string($port) || ! ctype_digit($port)) {
            return null;
        }
        $redis = ['host' => TestServiceHosts::redisHost(), 'port' => (int) $port, 'prefix' => 'nvl-translations-race-'.bin2hex(random_bytes(8)).':'];
        config(self::lockConfiguration($redis));

        return $redis;
    }

    /**
     * @param  array{host: string, port: int, prefix: string}  $redis
     * @return array<string, mixed>
     */
    public static function lockConfiguration(array $redis): array
    {
        return [
            'database.redis.client' => 'phpredis',
            'database.redis.options.prefix' => '',
            'database.redis.translations_race' => ['host' => $redis['host'], 'port' => $redis['port'], 'database' => 0],
            'cache.stores.translations_race' => ['driver' => 'redis', 'connection' => 'translations_race', 'lock_connection' => 'translations_race', 'prefix' => $redis['prefix']],
            'nvl-translations.locks.store' => 'translations_race',
        ];
    }

    /**
     * Start one worker; it writes `ready` in the barrier directory just before the operation.
     *
     * @param  array{host: string, port: int, prefix: string}  $redis
     * @param  array<string, mixed>  $operation
     */
    public static function start(string $directory, array $redis, array $operation): Process
    {
        $worker = new Process([
            PHP_BINARY,
            __DIR__.'/translations-lock-worker.php',
            json_encode(DB::connection()->getConfig(), JSON_THROW_ON_ERROR),
            json_encode(['directory' => $directory, 'redis' => $redis, ...$operation], JSON_THROW_ON_ERROR),
        ], env: ['XDEBUG_MODE' => 'off'], timeout: 60);
        $worker->start();

        return $worker;
    }

    /** Wait for a barrier file, failing early when the worker exits. */
    public static function await(Process $worker, string $path): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path)) {
            $worker->checkTimeout();
            if (! $worker->isRunning()) {
                throw new RuntimeException('A translation lock worker exited early with code ['.$worker->getExitCode().']: '.$worker->getErrorOutput().$worker->getOutput());
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("The translation lock barrier [{$path}] timed out.");
            }
            usleep(10000);
            clearstatcache();
        }
    }

    /**
     * Wait for the worker and return its JSON outcome.
     *
     * @return array<string, mixed>
     */
    public static function outcome(Process $worker): array
    {
        $worker->wait();
        if (! $worker->isSuccessful()) {
            throw new RuntimeException('A translation lock worker failed: '.$worker->getErrorOutput().$worker->getOutput());
        }
        $outcome = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return is_array($outcome) ? $outcome : throw new RuntimeException('A translation lock worker returned no outcome.');
    }

    public static function directory(): string
    {
        $directory = sys_get_temp_dir().'/nvl-translations-race-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create the translation lock barrier directory.');
        }

        return $directory;
    }

    public static function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}
