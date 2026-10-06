<?php

declare(strict_types=1);

namespace Nvl\Translations\Data;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Nvl\Data\Traits\DataTransform;
use Nvl\Translations\Rules\StringOrList;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Validated workspace-to-file export options. */
#[TypeScript]
final class ExportTranslationsData extends Data
{
    use DataTransform;

    /**
     * @param  array<array-key, mixed>|string|null  $scope
     * @param  array<array-key, mixed>|string|null  $locales
     */
    public function __construct(
        #[LiteralTypeScriptType('string | string[] | null')]
        public readonly string|array|null $scope = null,
        #[LiteralTypeScriptType('string | string[] | null')]
        public readonly string|array|null $locales = null,
        public readonly ?string $format = null,
        public readonly ?string $target = null,
        public readonly ?bool $prune = null,
        public readonly ?bool $dryRun = null,
        public readonly ?bool $force = null,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'scope' => ['nullable', new StringOrList(maximumItemLength: 255)],
            'scope.*' => ['string', 'max:255'],
            'locales' => ['nullable', new StringOrList(maximumItemLength: 35)],
            'locales.*' => ['string', 'max:35'],
            'format' => ['nullable', 'string', Rule::in(['php', 'json', 'both'])],
            'target' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'prune' => ['nullable', 'boolean'],
            'dryRun' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
        ];
    }

    public static function withValidator(Validator $validator): void
    {
        $validator->after(static function (Validator $validator): void {
            $input = $validator->getData();

            if (! filter_var($input['dryRun'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && ! filter_var($input['force'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $validator->errors()->add('force', trans('nvl-translations::translations/validation.force_required'));
            }
        });
    }

    /** @return list<string> */
    public function scopeTokens(): array
    {
        return TranslationOptionNormalizer::list($this->scope) ?? [];
    }

    /** @return list<string>|null */
    public function localeNames(): ?array
    {
        return TranslationOptionNormalizer::list($this->locales);
    }

    public function translationFormat(): string
    {
        return $this->format ?? 'both';
    }

    public function targetName(): string
    {
        return trim($this->target ?? 'source') ?: 'source';
    }

    public function shouldPrune(): bool
    {
        return $this->prune ?? false;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun ?? false;
    }
}
