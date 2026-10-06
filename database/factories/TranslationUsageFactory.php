<?php

declare(strict_types=1);

namespace Nvl\Translations\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Translations\Models\TranslationScanRun;
use Nvl\Translations\Models\TranslationUsage;

/**
 * Builds TranslationUsage fixture rows and their declared package parents.
 *
 * @extends Factory<TranslationUsage>
 *
 * @api
 */
final class TranslationUsageFactory extends Factory
{
    protected $model = TranslationUsage::class;

    /**
     * Prepare native parent and owner facts after Laravel expands relationships.
     *
     * @internal
     */
    public function configure(): static
    {
        $expandRelationships = true;

        return $this->state(function () use (&$expandRelationships): array {
            $expandRelationships = $this->expandRelationships;

            return [];
        })->afterMaking(function (TranslationUsage $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('scan_id') !== null) {
                $parent = TranslationScanRun::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('scan_id')));
                FactoryGuard::parent($parent, $model);
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<TranslationUsage>, mixed>
     */
    public function definition(): array
    {
        return [
            'scan_id' => TranslationScanRun::factory(),
            'format' => 'json',
            'full_key' => $this->faker->sentence(3),
            'file_path' => 'resources/views/factory.blade.php',
            'line' => 1,
            'last_seen_at' => now(),
        ];
    }

    /**
     * Associate an admitted persisted TranslationScanRun parent.
     *
     * @api
     */
    public function forScan(TranslationScanRun $parent): static
    {
        FactoryGuard::parent($parent, new TranslationUsage);

        return $this->state([
            'scan_id' => $parent->getKey(),
        ]);
    }
}
