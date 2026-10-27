<?php

declare(strict_types=1);

namespace Nvl\Translations\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Translations\Models\TranslationOverride;
use Nvl\Translations\Services\TranslationOverrideModels;

/**
 * Creates an allowed override using the same configured model and ownership boundary.
 *
 * @extends Factory<TranslationOverride>
 */
final class TranslationOverrideFactory extends Factory
{
    protected $model = TranslationOverride::class;

    /** @return class-string<TranslationOverride> */
    public function modelName(): string
    {
        return app(TranslationOverrideModels::class)->modelClass();
    }

    /** @return array{key:string, locale:string, value:string, revision:int} */
    public function definition(): array
    {
        $keys = config('nvl-translations.ownership.keys', []);
        if (! is_array($keys) || ! isset($keys[0]) || ! is_string($keys[0])) {
            throw new OwnershipConfigurationInvalid('Configure an allowed override key before creating a factory fixture.');
        }

        return ['key' => $keys[0], 'locale' => 'en', 'value' => $this->faker->sentence(), 'revision' => 1];
    }
}
