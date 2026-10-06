<?php

declare(strict_types=1);

namespace Nvl\Translations\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Translations\Models\TranslationEntry;

/**
 * Builds TranslationEntry fixture rows and their declared package parents.
 *
 * @extends Factory<TranslationEntry>
 *
 * @api
 */
final class TranslationEntryFactory extends Factory
{
    protected $model = TranslationEntry::class;

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<TranslationEntry>, mixed>
     */
    public function definition(): array
    {
        return [
            'scope_type' => 'app',
            'scope_name' => 'app',
            'locale' => 'en',
            'format' => 'json',
            'group' => '*',
            'key' => $this->faker->unique()->sentence(3),
            'value' => $this->faker->sentence(),
            'sync_status' => 'synchronized',
            'revision' => 1,
        ];
    }
}
