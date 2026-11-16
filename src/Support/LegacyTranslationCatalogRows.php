<?php

declare(strict_types=1);

namespace Nvl\Translations\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Nvl\Translations\Enums\TranslationFormat;
use Nvl\Translations\Enums\TranslationScopeType;
use Nvl\Translations\Enums\TranslationSyncStatus;
use Nvl\Translations\Exceptions\InvalidTranslationInputException;
use Nvl\Translations\Services\TranslationPathGuard;
use stdClass;

/**
 * Converts the standalone legacy catalog into native row identities and sync state.
 *
 * @internal
 *
 * @phpstan-type Row array{id:string, identity_hash:string, ...<string, bool|int|string|null>}
 */
final readonly class LegacyTranslationCatalogRows
{
    public const array EntryColumns = ['id', 'scope_type', 'scope_name', 'locale', 'format', 'group', 'key', 'value', 'source_hash', 'is_missing', 'last_imported_at', 'created_at', 'updated_at'];

    public const array UsageColumns = ['id', 'scope_type', 'scope_name', 'format', 'full_key', 'file_path', 'line', 'last_seen_at', 'created_at', 'updated_at'];

    public const array EntryNativeColumns = ['identity_hash', 'revision', 'sync_status', 'conflict_metadata', 'last_exported_at'];

    public const array UsageNativeColumns = ['identity_hash', 'scan_id'];

    public function __construct(private TranslationPathGuard $paths) {}

    /**
     * Normalize a legacy entry. An explicitly supplied source value resolves an edited baseline.
     *
     * @param  array<string, string|null>  $baselines
     * @return Row
     */
    public function entry(stdClass $row, array &$baselines): array
    {
        $id = $this->uuid($row->id);
        [$type, $name] = $this->scope($row->scope_type, $row->scope_name, $id);
        if ($type === null || $name === null) {
            throw new InvalidTranslationInputException("Legacy translation entry [{$id}] requires a scope.");
        }

        $locale = $this->text($row->locale, 'locale', $id);
        if ($this->paths->locale($locale) !== $locale) {
            throw new InvalidTranslationInputException("Legacy translation entry [{$id}] has a noncanonical locale.");
        }

        $format = $this->format($row->format, $id);
        $key = $this->text($row->key, 'key', $id);
        $group = $this->group($format, $row->group, $key, $id);
        $value = $row->value === null ? null : $this->text($row->value, 'value', $id, allowEmpty: true);
        $sourceHash = $this->sourceHash($row->source_hash, $value, $id, $baselines);
        $missing = match ($row->is_missing) {
            false, 0, '0' => false,
            true, 1, '1' => true,
            default => throw new InvalidTranslationInputException("Invalid missing flag on legacy translation entry [{$id}]."),
        };
        $status = match (true) {
            $missing => TranslationSyncStatus::Missing,
            $sourceHash !== null && $sourceHash !== TranslationValueHash::make($value) => TranslationSyncStatus::Edited,
            default => TranslationSyncStatus::Synchronized,
        };

        return [
            'id' => $id,
            'identity_hash' => TranslationIdentity::entry($type, $name, $locale, $format, $group, $key),
            'scope_type' => $type,
            'scope_name' => $name,
            'locale' => $locale,
            'format' => $format,
            'group' => $group,
            'key' => $key,
            'value' => $value,
            'source_hash' => $sourceHash,
            'is_missing' => $missing,
            'revision' => 1,
            'sync_status' => $status->value,
            'conflict_metadata' => null,
            'last_imported_at' => $this->timestamp($row->last_imported_at, $id),
            'last_exported_at' => null,
            ...$this->timestamps($row, $id),
        ];
    }

    /** @return Row */
    public function usage(stdClass $row): array
    {
        $id = $this->uuid($row->id);
        [$type, $name] = $this->scope($row->scope_type, $row->scope_name, $id);
        $format = $this->format($row->format, $id);
        $key = $this->text($row->full_key, 'full_key', $id);
        $path = $this->text($row->file_path, 'file_path', $id);
        $line = filter_var($row->line, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        $lastSeen = $this->timestamp($row->last_seen_at, $id);
        if ($line === false || $lastSeen === null) {
            throw new InvalidTranslationInputException("Legacy translation usage [{$id}] requires a line and a last-seen timestamp.");
        }

        return [
            'id' => $id,
            'identity_hash' => TranslationIdentity::usage($type, $name, $format, $key, $path, $line),
            'scan_id' => null,
            'scope_type' => $type,
            'scope_name' => $name,
            'format' => $format,
            'full_key' => $key,
            'file_path' => $path,
            'line' => $line,
            'last_seen_at' => $lastSeen,
            ...$this->timestamps($row, $id),
        ];
    }

    /**
     * Compare every adopted field, normalizing database representations of times and scalars.
     *
     * @param  Row  $expected
     */
    public function matches(stdClass $actual, array $expected): bool
    {
        foreach ($expected as $field => $value) {
            $stored = $actual->{$field};
            $stored = match ($field) {
                'last_imported_at', 'last_exported_at', 'last_seen_at', 'created_at', 'updated_at' => $this->timestamp($stored, $this->uuid($actual->id)),
                'line', 'revision' => is_numeric($stored) ? (int) $stored : $stored,
                'is_missing' => (bool) $stored,
                default => $stored,
            };
            if ($stored !== $value) {
                return false;
            }
        }

        return true;
    }

    private function uuid(mixed $id): string
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            throw new InvalidTranslationInputException('Legacy translation catalog records require valid UUIDs.');
        }

        return $id;
    }

    /** @return array{string|null, string|null} */
    private function scope(mixed $type, mixed $name, string $id): array
    {
        if ($type === null && $name === null) {
            return [null, null];
        }
        if ($type === 'mail' && $name === 'mail') {
            return ['app', 'app'];
        }
        if (! is_string($type) || TranslationScopeType::tryFrom($type) === null || ! is_string($name)
            || preg_match('/\A[A-Za-z0-9_.-]{1,120}\z/', $name) !== 1 || ($type === 'app' && $name !== 'app')) {
            throw new InvalidTranslationInputException("Invalid scope on legacy translation record [{$id}].");
        }

        return [$type, $name];
    }

    private function format(mixed $format, string $id): string
    {
        if (! is_string($format) || TranslationFormat::tryFrom($format) === null) {
            throw new InvalidTranslationInputException("Invalid format on legacy translation record [{$id}].");
        }

        return $format;
    }

    private function group(string $format, mixed $group, string $key, string $id): string
    {
        if ($format === 'json' && ($group === null || $group === '*')) {
            return '*';
        }
        if ($format !== 'php' || ! is_string($group) || $this->paths->group($group) !== $group
            || in_array('.', explode('/', $group), true) || in_array('', explode('.', $key), true)) {
            throw new InvalidTranslationInputException("Invalid group or key on legacy translation entry [{$id}].");
        }

        return $group;
    }

    /** @param array<string, string|null> $baselines */
    private function sourceHash(mixed $hash, ?string $value, string $id, array &$baselines): ?string
    {
        $reviewed = array_key_exists($id, $baselines);
        if ($hash === null && ! $reviewed) {
            return null;
        }

        $baseline = $reviewed ? $baselines[$id] : $value;
        if (! is_string($hash) || ! hash_equals($hash, hash('sha256', $baseline ?? '__NULL__'))) {
            throw new InvalidTranslationInputException("Legacy source hash for entry [{$id}] requires its original source value in source_values.");
        }
        unset($baselines[$id]);

        return TranslationValueHash::make($baseline);
    }

    private function text(mixed $value, string $field, string $id, bool $allowEmpty = false): string
    {
        if (! is_string($value) || (! $allowEmpty && $value === '') || str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidTranslationInputException("Invalid {$field} on legacy translation record [{$id}].");
        }

        return $value;
    }

    /** @return array{created_at:string|null, updated_at:string|null} */
    private function timestamps(stdClass $row, string $id): array
    {
        return [
            'created_at' => $this->timestamp($row->created_at, $id),
            'updated_at' => $this->timestamp($row->updated_at, $id),
        ];
    }

    private function timestamp(mixed $value, string $id): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::?\d{2})?)?\z/', $value) !== 1) {
            throw new InvalidTranslationInputException("Invalid timestamp on legacy translation record [{$id}].");
        }
        $time = CarbonImmutable::parse($value, 'UTC');
        if (DateTimeImmutable::getLastErrors() !== false) {
            throw new InvalidTranslationInputException("Invalid timestamp on legacy translation record [{$id}].");
        }

        return $time->utc()->format('Y-m-d H:i:s.uP');
    }
}
