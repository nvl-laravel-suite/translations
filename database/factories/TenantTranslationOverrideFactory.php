<?php

declare(strict_types=1);

namespace Nvl\Translations\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Translations\Models\TenantTranslationOverride;

/**
 * Builds TenantTranslationOverride fixture rows and their declared package parents.
 *
 * @extends Factory<TenantTranslationOverride>
 *
 * @api
 */
final class TenantTranslationOverrideFactory extends Factory
{
    protected $model = TenantTranslationOverride::class;

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
        })->afterMaking(function (TenantTranslationOverride $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            FactoryGuard::root($model, 'translations.overrides');
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<TenantTranslationOverride>, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'ownership_key' => null,
            'key' => $this->faker->unique()->slug(3),
            'locale' => 'en',
            'value' => $this->faker->sentence(),
            'revision' => 1,
        ];
    }
}
