<?php

declare(strict_types=1);

namespace Nvl\Translations\Tests\Fixtures\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * Update payload for translation values.
 *
 * @api
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
final class UpdateTranslationEntryPayload extends Data
{
    /**
     * @param  string|null  $value  New translation value
     */
    public function __construct(
        public readonly ?string $value,
        #[MapInputName('expected_revision')] #[MapOutputName('expected_revision')] public readonly int $expectedRevision,
    ) {}

    /**
     * Validation rules.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'value' => ['required', 'nullable', 'string'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Validation messages.
     *
     * @return array<string, mixed>
     */
    public static function messages(): array
    {
        return (array) trans('nvl-translations::translations/validation.messages');
    }

    /**
     * Validation attributes.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return (array) trans('nvl-translations::translations/validation.attributes');
    }
}
