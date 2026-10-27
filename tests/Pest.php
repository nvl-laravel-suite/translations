<?php

declare(strict_types=1);

use Nvl\Translations\Tests\CommittedWorkspaceTestCase;
use Nvl\Translations\Tests\TestCase;

$workspaceWorkflows = [
    'TranslationFileSyncTest.php',
    'TranslationCatalogIntegrityTest.php',
    'TranslationsConsumerContractsTest.php',
    'TranslationsApiTest.php',
];
foreach (glob(__DIR__.'/Feature/*.php') ?: [] as $file) {
    uses(in_array(basename($file), $workspaceWorkflows, true) ? CommittedWorkspaceTestCase::class : TestCase::class)->in($file);
}
