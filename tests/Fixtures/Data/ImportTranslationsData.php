<?php

declare(strict_types=1);

namespace Nvl\Translations\Tests\Fixtures\Data;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Nvl\Translations\Tests\Fixtures\StringOrList;

/** Validated file-to-workspace import options. */
final class ImportTranslationsData
{
    /** @param array<array-key, mixed>|string|null $scope */
    public function __construct(
        public readonly string|array|null $scope = null,
        public readonly ?string $format = null,
        public readonly ?bool $dryRun = null,
    ) {}

    /** Validate file import options at the host HTTP boundary. */
    public static function fromRequest(Request $request): self
    {
        $data = $request->validate(self::rules());

        return new self(
            scope: $data['scope'] ?? null,
            format: $data['format'] ?? null,
            dryRun: isset($data['dryRun']) ? (bool) $data['dryRun'] : null,
        );
    }

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
