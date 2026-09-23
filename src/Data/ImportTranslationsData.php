<?php

declare(strict_types=1);

namespace Nvl\Translations\Data;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Translations\Rules\StringOrList;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Validated file-to-workspace import options. */
#[TypeScript]
final class ImportTranslationsData extends Data
{
    use DataTransform;

    /** @param array<array-key, mixed>|string|null $scope */
    public function __construct(
        #[LiteralTypeScriptType('string | string[] | null')]
        public readonly string|array|null $scope = null,
        public readonly ?string $format = null,
        public readonly ?bool $dryRun = null,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'scope' => ['nullable', new StringOrList(maximumItemLength: 255)],
            'scope.*' => ['string', 'max:255'],
            'format' => ['nullable', 'string', Rule::in(['php', 'json', 'both'])],
            'dryRun' => ['nullable', 'boolean'],
        ];
    }

    /** @return list<string> */
    public function scopeTokens(): array
    {
        return TranslationOptionNormalizer::list($this->scope) ?? [];
    }

    public function translationFormat(): string
    {
        return $this->format ?? 'both';
    }

    public function isDryRun(): bool
    {
        return $this->dryRun ?? false;
    }
}
