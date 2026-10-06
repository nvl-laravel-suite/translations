<?php

declare(strict_types=1);

namespace Nvl\Translations\Actions\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Translations\Contracts\ScanTranslationsContract;
use Nvl\Translations\Events\TranslationsScanned;
use Nvl\Translations\Services\SourceTranslationWorkspace;
use Nvl\Translations\Services\TranslationProcessLock;
use Nvl\Translations\Services\TranslationScanService;

/**
 * Runs translation usage scanner.
 *
 * @api
 */
final class ScanTranslationsAction implements ScanTranslationsContract
{
    /**
     * @param  TranslationScanService  $scanService  Scanner service
     */
    public function __construct(
        private readonly TranslationScanService $scanService,
        private readonly TranslationProcessLock $lock,
        private readonly SourceTranslationWorkspace $workspace,
        private DomainEventDispatcher $domainEvents,
    ) {}

    /**
     * @return array{files:int,hits:int,scanned_at:CarbonImmutable}
     */
    public function execute(): array
    {
        $this->workspace->authorize();
        $result = $this->lock->execute(
            'scan',
            fn (): array => $this->scanService->execute(),
        );
        $this->domainEvents->dispatch(new TranslationsScanned($result), DB::connection(PackageStorage::connection('translations')));

        return $result;
    }
}
