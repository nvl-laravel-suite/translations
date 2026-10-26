<?php

declare(strict_types=1);

namespace Nvl\Translations\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Support\Enums\SystemActor;
use Nvl\Translations\Services\TranslationScanner;

/**
 * Scan source files for translation key usage.
 */
final class TranslationsScanCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nvl:translations:scan';

    /**
     * @var string
     */
    protected $description = 'Scan source files to collect translation key usage.';

    /**
     * Execute the command.
     */
    public function handle(TranslationScanner $action): int
    {
        $result = $action->scan(SystemActor::Background);

        $this->info(sprintf(
            'Scanned %d files and captured %d usage hits at %s.',
            $result['files'],
            $result['hits'],
            $result['scanned_at']->toDateTimeString(),
        ));

        return self::SUCCESS;
    }
}
