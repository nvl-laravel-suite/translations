<?php

declare(strict_types=1);

namespace Nvl\Translations\Actions;

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Str;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Services\OwnershipContextResolver;
use Nvl\Translations\Contracts\ExportTranslationOverridesContract;
use Nvl\Translations\Data\TranslationOverrideExportData;
use Nvl\Translations\Models\TranslationOverride;
use Nvl\Translations\Services\TranslationOverrideKeys;
use Nvl\Translations\Services\TranslationOverrideModels;
use RuntimeException;

/**
 * Writes a private owner artifact without touching source files or legacy tenant storage.
 *
 * @api
 */
final readonly class ExportTranslationOverridesAction implements ExportTranslationOverridesContract
{
    /** Retain neutral admission, canonical models and private storage. */
    public function __construct(private OwnershipBoundary $boundary, private OwnershipContextResolver $context, private TranslationOverrideModels $models, private TranslationOverrideKeys $keys, private Factory $files) {}

    /** Export the freshly admitted owner's allowed copy with full native identity. */
    public function execute(string $disk): TranslationOverrideExportData
    {
        $this->boundary->key('translations.overrides', 'export');
        $owner = $this->context->current()->requireOwner();
        $class = $this->models->modelClass();
        $rows = $this->boundary->query((new $class)->newQuery(), 'translations.overrides')->orderBy('key')->orderBy('locale')->get();
        $values = $rows->map(function (TranslationOverride $row): array {
            $this->keys->assertAllowed($row->key, $row->locale);

            return ['key' => $row->key, 'locale' => $row->locale, 'value' => $row->value, 'revision' => $row->revision];
        })->all();
        $payload = json_encode(['owner' => $owner->toArray(), 'translations' => $values], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $path = 'owners/'.substr($owner->scopeKey(), 6).'/translations/'.Str::uuid().'.json';
        $this->boundary->key('translations.overrides', 'export');
        if (! $this->files->disk($disk)->put($path, $payload, ['visibility' => 'private'])) {
            throw new RuntimeException('Unable to write owner translation artifact.');
        }

        return new TranslationOverrideExportData($owner, $disk, $path, hash('sha256', $payload), strlen($payload));
    }
}
