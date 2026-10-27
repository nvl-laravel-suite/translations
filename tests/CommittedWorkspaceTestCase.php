<?php

declare(strict_types=1);

namespace Nvl\Translations\Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;

/** Isolates source workspace effects with real root commits rather than a test rollback wrapper. */
abstract class CommittedWorkspaceTestCase extends TestCase
{
    use DatabaseMigrations;

    /** DatabaseMigrations supplies isolation; source export must observe real transaction levels. */
    public function refreshDatabase(): void {}
}
