<?php

declare(strict_types=1);

namespace Nvl\Translations\Services;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Exceptions\OwnershipException;
use Nvl\Support\Ownership\Services\OwnershipConstraintInspector;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Definitions\Tables\TranslationsTables;
use Nvl\Translations\Support\TranslationConfiguration;
use RuntimeException;
use Throwable;

/**
 * Inspects workspace schema, profiles, and bindings without mutation.
 *
 * @internal
 */
final readonly class TranslationsDoctor
{
    public function __construct(
        private TranslationsAuthorization $authorization,
        private TranslationScopeResolver $scopes,
        private TranslationPathGuard $paths,
        private TranslationScanner $scanner,
        private OwnershipResourceRegistry $ownership,
        private OwnershipReadiness $readiness,
        private OwnershipConstraintInspector $constraints,
        private TranslationOverrideKeys $overrideKeys,
    ) {}

    /**
     * @return list<array{key:string,severity:string,passed:bool,message:string}>
     */
    public function inspect(): array
    {
        return [
            ...$this->schemaChecks(),
            ...$this->ownerOverrideChecks(),
            $this->scopeCheck(),
            $this->exportTargetsCheck(),
            $this->backupCheck(),
            $this->lockCheck(),
            $this->scannerCheck(),
            $this->authorizationCheck(),
        ];
    }

    /** Inspect exact native source readiness without requiring an admitted owner.
     * @return list<array{key:string,severity:string,passed:bool,message:string}>
     */
    private function ownerOverrideChecks(): array
    {
        $definition = $this->ownership->all()['translations.overrides'] ?? null;
        if ($definition === null || $definition->ownership->strategy === 'unscoped') {
            return [];
        }
        try {
            $this->overrideKeys->all();
            $this->readiness->assertActive('translations.overrides');
            $model = new $definition->model;
            $this->constraints->assertUniqueIndexes($model->getConnection(), $model->getTable(), [['owner_scope_key', 'key', 'locale']]);

            return [['key' => 'ownership.overrides', 'severity' => 'error', 'passed' => true, 'message' => 'Native override storage, keys and identity uniqueness are ready.']];
        } catch (OwnershipException $exception) {
            return [['key' => 'ownership.overrides', 'severity' => 'error', 'passed' => false, 'message' => $exception->getMessage()]];
        }
    }

    /** @return list<array{key:string,severity:string,passed:bool,message:string}> */

    /**
     * @return list<array{key:string,severity:string,passed:bool,message:string}>
     */
    private function schemaChecks(): array
    {
        $tables = [
            TranslationsTables::get(TranslationsTables::Entries) => [
                'columns' => [
                    'id', 'identity_hash', 'scope_type', 'scope_name', 'locale', 'format', 'group',
                    'key', 'value', 'source_hash', 'is_missing', 'revision', 'sync_status',
                    'conflict_metadata', 'last_imported_at', 'last_exported_at',
                    'created_at', 'updated_at',
                ],
                'identity' => true,
            ],
            TranslationsTables::get(TranslationsTables::ScanRuns) => [
                'columns' => ['id', 'scanned_at', 'files', 'hits', 'created_at', 'updated_at'],
                'identity' => false,
            ],
            TranslationsTables::get(TranslationsTables::Usages) => [
                'columns' => [
                    'id', 'identity_hash', 'scan_id', 'scope_type', 'scope_name', 'format',
                    'full_key', 'file_path', 'line', 'last_seen_at', 'created_at', 'updated_at',
                ],
                'identity' => true,
            ],
        ];
        $checks = [];

        foreach ($tables as $table => $requirements) {
            $exists = Schema::hasTable($table);
            $checks[] = [
                'key' => "schema.table.{$table}",
                'severity' => 'error',
                'passed' => $exists,
                'message' => $exists ? "Table [{$table}] exists." : "Table [{$table}] is missing.",
            ];

            if (! $exists) {
                continue;
            }

            $missing = array_values(array_filter(
                $requirements['columns'],
                static fn (string $column): bool => ! Schema::hasColumn($table, $column),
            ));
            $checks[] = [
                'key' => "schema.columns.{$table}",
                'severity' => 'error',
                'passed' => $missing === [],
                'message' => $missing === []
                    ? "Required columns exist on [{$table}]."
                    : 'Missing columns: '.implode(', ', $missing).'.',
            ];

            if (! $requirements['identity']) {
                continue;
            }

            $hasIdentityIndex = false;
            foreach (Schema::getIndexes($table) as $index) {
                if (! is_array($index)) {
                    continue;
                }

                if (($index['unique'] ?? false) === true
                    && ($index['columns'] ?? []) === ['identity_hash']) {
                    $hasIdentityIndex = true;

                    break;
                }
            }
            $checks[] = [
                'key' => "schema.identity.{$table}",
                'severity' => 'error',
                'passed' => $hasIdentityIndex,
                'message' => $hasIdentityIndex
                    ? "Case-sensitive identity uniqueness exists on [{$table}]."
                    : "A unique identity_hash index is missing from [{$table}].",
            ];
        }

        return $checks;
    }

    /** @return array{key:string, severity:string, passed:bool, message:string} */
    private function scopeCheck(): array
    {
        try {
            $count = count($this->scopes->discoverScopes());

            return [
                'key' => 'profiles.sources',
                'severity' => 'error',
                'passed' => $count > 0,
                'message' => "{$count} source profile(s) are valid.",
            ];
        } catch (Throwable $exception) {
            return [
                'key' => 'profiles.sources',
                'severity' => 'error',
                'passed' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /** @return array{key:string, severity:string, passed:bool, message:string} */
    private function backupCheck(): array
    {
        if (! (bool) config('nvl-translations.backup.enabled', true)) {
            return [
                'key' => 'files.backup_directory',
                'severity' => 'warning',
                'passed' => false,
                'message' => 'Translation file backups are disabled.',
            ];
        }

        try {
            $directory = config('nvl-translations.backup.directory');

            if (! is_string($directory)) {
                throw new RuntimeException('The backup directory must be a string.');
            }

            $this->paths->root($directory);

            return [
                'key' => 'files.backup_directory',
                'severity' => 'error',
                'passed' => true,
                'message' => 'The configured backup directory is safe.',
            ];
        } catch (Throwable $exception) {
            return [
                'key' => 'files.backup_directory',
                'severity' => 'error',
                'passed' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /** @return array{key:string, severity:string, passed:bool, message:string} */
    private function authorizationCheck(): array
    {
        $configuredAbility = config('nvl-translations.authorization.ability');
        $configuredName = is_string($configuredAbility) ? trim($configuredAbility) : '';
        $configured = $configuredName !== '' && Gate::has($configuredName);
        $usesCustomBoundary = ! $this->authorization instanceof ConfiguredTranslationsAuthorization;
        $ready = ! (bool) config('nvl-translations.routes.enabled', false)
            || $configured
            || $usesCustomBoundary;

        return [
            'key' => 'binding.authorization',
            'severity' => 'error',
            'passed' => $ready,
            'message' => match (true) {
                ! (bool) config('nvl-translations.routes.enabled', false) => 'Authorization is bound; management routes are disabled.',
                $configured => 'The configured Gate ability protects management routes.',
                $usesCustomBoundary => 'A custom TranslationsAuthorization implementation protects management routes.',
                $configuredName !== '' => "Configured Gate ability [{$configuredName}] is not registered.",
                default => 'Management routes require a configured Gate ability or custom authorization binding.',
            },
        ];
    }

    /** @return array{key:string, severity:string, passed:bool, message:string} */
    private function scannerCheck(): array
    {
        try {
            $this->scanner->validateConfiguration();

            return [
                'key' => 'scan.configuration',
                'severity' => 'error',
                'passed' => true,
                'message' => 'Scanner roots, extensions, and patterns are valid.',
            ];
        } catch (Throwable $exception) {
            return [
                'key' => 'scan.configuration',
                'severity' => 'error',
                'passed' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /** @return array{key:string, severity:string, passed:bool, message:string} */
    private function exportTargetsCheck(): array
    {
        try {
            $configured = config('nvl-translations.export_targets', []);

            if (! is_array($configured)) {
                throw new RuntimeException('translations.export_targets must be an associative target map.');
            }

            $scopes = $this->scopes->discoverScopes();
            $this->scopes->resolveExportPaths($scopes, 'source');

            foreach (array_keys($configured) as $target) {
                if (! is_string($target)) {
                    throw new RuntimeException('Every translations.export_targets key must be a string.');
                }

                if ($target === 'source') {
                    if (($configured[$target] ?? null) !== []) {
                        throw new RuntimeException('The reserved source export target must use an empty map.');
                    }

                    continue;
                }

                $this->scopes->resolveExportPaths($scopes, $target);
            }

            return [
                'key' => 'profiles.export_targets',
                'severity' => 'error',
                'passed' => true,
                'message' => 'Configured export targets are distinct from sources and backups.',
            ];
        } catch (Throwable $exception) {
            return [
                'key' => 'profiles.export_targets',
                'severity' => 'error',
                'passed' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /** @return array{key:string, severity:string, passed:bool, message:string} */
    private function lockCheck(): array
    {
        try {
            TranslationConfiguration::positiveInteger('nvl-translations.lock.seconds', 300);
            TranslationConfiguration::nonNegativeInteger('nvl-translations.lock.wait_seconds', 0);
            $configuredStore = config('nvl-translations.locks.store');
            $store = is_string($configuredStore) && trim($configuredStore) !== ''
                ? trim($configuredStore)
                : null;
            $cacheStore = Cache::store($store)->getStore();
            $supportsLocks = $cacheStore instanceof LockProvider;
            $driver = $cacheStore::class;

            return [
                'key' => 'lock.workspace',
                'severity' => 'error',
                'passed' => $supportsLocks,
                'message' => $supportsLocks
                    ? "Workspace locking is supported by [{$driver}]."
                    : "Cache store [{$driver}] does not support atomic locks.",
            ];
        } catch (Throwable $exception) {
            return [
                'key' => 'lock.workspace',
                'severity' => 'error',
                'passed' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }
}
