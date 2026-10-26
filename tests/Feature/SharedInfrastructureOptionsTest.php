<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Nvl\Translations\Models\TranslationScanRun;
use Nvl\Translations\Models\TranslationUsage;
use Nvl\Translations\Services\TranslationProcessLock;

it('uses the inherited Core store for translation process locking', function (): void {
    config([
        'nvl-translations.locks.store' => null,
        'nvl-translations.lock.store' => null,
        'nvl-core.locks.store' => 'shared-translation-locks',
        'cache.stores.shared-translation-locks' => ['driver' => 'array'],
    ]);
    $repository = Cache::store('shared-translation-locks');
    Cache::shouldReceive('store')->with('shared-translation-locks')->once()->andReturn($repository);
    expect(app(TranslationProcessLock::class)->execute('sync', fn (): string => 'synchronized'))->toBe('synchronized');
});

it('admits persisted native scan parents and rejects transient factory parents', function (): void {
    $scan = TranslationScanRun::factory()->create();
    $usage = TranslationUsage::factory()->forScan($scan)->create();
    expect($usage->scan_id)->toBe($scan->id);
    expect(fn () => TranslationUsage::factory()->forScan(new TranslationScanRun))
        ->toThrow(InvalidArgumentException::class);
});
