<?php

declare(strict_types=1);

namespace Nvl\Translations\Tests\Fixtures\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translations\Enums\TranslationsResponseCode;
use Nvl\Translations\Models\TranslationEntry;
use Nvl\Translations\Services\TranslationCatalog;
use Nvl\Translations\Services\TranslationExporter;
use Nvl\Translations\Services\TranslationImporter;
use Nvl\Translations\Services\TranslationScanner;
use Nvl\Translations\Tests\Fixtures\Data\ExportTranslationsData;
use Nvl\Translations\Tests\Fixtures\Data\ImportTranslationsData;
use Nvl\Translations\Tests\Fixtures\Data\TranslationEntryPayload;
use Nvl\Translations\Tests\Fixtures\TranslationFilterOptions;

/** Consumer-owned HTTP transport for retained endpoint regression assertions. */
final class TranslationsApiController extends Controller
{
    public function index(Request $request, TranslationCatalog $catalog, TranslationFilterOptions $options): JsonResponse
    {
        $input = $request->query();
        $input['per_page'] ??= $input['per_page'] ?? 25;
        $query = Validator::make($input, [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'filter' => ['nullable', 'array', 'max:25'],
            'sort' => ['nullable'],
        ])->validate();
        $filters = $input['filter'] ?? [];
        if (! is_array($filters)) {
            throw ValidationException::withMessages(['filter' => 'Expected filters.']);
        }
        $allowed = ['scope_type', 'scope_name', 'locale', 'format', 'group', 'key', 'search', 'missing_value', 'changed_since_import', 'is_missing'];
        foreach ($filters as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages(['filter.'.$key => 'Unknown filter.']);
            }
            if (in_array($key, ['missing_value', 'changed_since_import', 'is_missing'], true)) {
                $filters[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($filters[$key] === null) {
                    throw ValidationException::withMessages(['filter.'.$key => 'Expected boolean.']);
                }
            }
        }
        if (isset($input['sort'])) {
            $filters['sort'] = explode(',', (string) $input['sort']);
        }
        $pageSize = (int) ($query['per_page'] ?? $query['limit'] ?? 25);
        $entries = $catalog->paginate(SystemActor::Background, $filters, $pageSize, max(1, (int) ($input['page'] ?? 1)));

        return response()->json(['data' => ['entries' => [
            'items' => array_map(static fn (TranslationEntry $entry): array => TranslationEntryPayload::fromModel($entry)->toArray(), $entries->items()),
            'meta' => ['currentPage' => $entries->currentPage(), 'per_page' => $entries->perPage(), 'total' => $entries->total(), 'lastPage' => $entries->lastPage()],
        ], ...$options->execute()]]);
    }

    public function update(Request $request, TranslationEntry $entry, TranslationCatalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'value' => ['present', 'nullable', 'string'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ], (array) trans('nvl-translations::translations/validation.messages'), (array) trans('nvl-translations::translations/validation.attributes'));
        $updated = $catalog->update(SystemActor::Background, $entry, ['value' => $data['value'], 'expected_revision' => (int) $data['expected_revision']]);

        return response()->json(['data' => TranslationEntryPayload::fromModel($updated)->toArray(), 'code' => TranslationsResponseCode::Updated->value]);
    }

    public function import(Request $request, TranslationImporter $importer): JsonResponse
    {
        $data = ImportTranslationsData::fromRequest($request);

        return response()->json(['data' => $importer->import(SystemActor::Background, $data->scopeTokens(), $data->translationFormat(), $data->isDryRun()), 'code' => TranslationsResponseCode::Imported->value]);
    }

    public function export(Request $request, TranslationExporter $exporter): JsonResponse
    {
        $data = ExportTranslationsData::fromRequest($request);

        return response()->json(['data' => $exporter->export(SystemActor::Background, $data->scopeTokens(), $data->localeNames(), $data->translationFormat(), $data->targetName(), $data->shouldPrune(), $data->isDryRun()), 'code' => TranslationsResponseCode::Exported->value]);
    }

    public function scan(TranslationScanner $scanner): JsonResponse
    {
        $result = $scanner->scan(SystemActor::Background);

        return response()->json(['data' => ['files' => $result['files'], 'hits' => $result['hits'], 'scannedAt' => $result['scanned_at']->toIso8601String()], 'code' => TranslationsResponseCode::Scanned->value]);
    }
}
