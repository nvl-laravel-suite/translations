<?php

declare(strict_types=1);

namespace Nvl\Translations\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Translations\Models\TranslationScanRun;

/**
 * Builds TranslationScanRun fixture rows and their declared package parents.
 *
 * @extends Factory<TranslationScanRun>
 *
 * @api
 */
final class TranslationScanRunFactory extends Factory
{
    protected $model = TranslationScanRun::class;

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<TranslationScanRun>, mixed>
     */
    public function definition(): array
    {
        return [
            'scanned_at' => now(),
            'files' => 0,
            'hits' => 0,
        ];
    }
}
