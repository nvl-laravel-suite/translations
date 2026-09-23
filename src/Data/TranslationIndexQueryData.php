<?php

declare(strict_types=1);

namespace Nvl\Translations\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Validated translation catalog query. */
#[MapInputName(SnakeCaseMapper::class)]
#[TypeScript]
final class TranslationIndexQueryData extends Data
{
    use DataTransform;

    /** @param array<string, mixed>|null $filter */
    public function __construct(
        public readonly ?int $perPage = null,
        public readonly ?int $limit = null,
        public readonly ?array $filter = null,
        public readonly mixed $sort = null,
    ) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'filter' => ['nullable', 'array', 'max:25'],
            'sort' => ['nullable'],
        ];
    }

    public function pageSize(int $fallback = 50): int
    {
        return $this->perPage ?? $this->limit ?? $fallback;
    }
}
