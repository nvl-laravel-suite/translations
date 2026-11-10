<?php

declare(strict_types=1);

namespace Nvl\Translations\Tests\Fixtures\Data;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Nvl\Translations\Tests\Fixtures\StringOrList;

/** Validated workspace-to-file export options. */
final class ExportTranslationsData
{
    /**
     * @param  array<array-key, mixed>|string|null  $scope
     * @param  array<array-key, mixed>|string|null  $locales
     */
    public function __construct(
        public readonly string|array|null $scope = null,
        public readonly string|array|null $locales = null,
        public readonly ?string $format = null,
        public readonly ?string $target = null,
        public readonly ?bool $prune = null,
        public readonly ?bool $dryRun = null,
        public readonly ?bool $force = null,
    ) {}

    /** Validate export options without coupling the consumer fixture to a DTO runtime. */
    public static function fromRequest(Request $request): self
    {
        $validator = ValidatorFacade::make($request->all(), self::rules());
        self::withValidator($validator);
        $data = $validator->validate();

        return new self(
            scope: $data['scope'] ?? null,
            locales: $data['locales'] ?? null,
            format: $data['format'] ?? null,
            target: $data['target'] ?? null,
            prune: isset($data['prune']) ? (bool) $data['prune'] : null,
            dryRun: isset($data['dryRun']) ? (bool) $data['dryRun'] : null,
            force: isset($data['force']) ? (bool) $data['force'] : null,
        );
    }

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
