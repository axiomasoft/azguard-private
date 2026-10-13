<?php

declare(strict_types=1);

namespace AzGuard\Tests\Modules\Audit\Contracts;

use AzGuard\Audit\Internal\JournalParticipant;
use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Testing\Contracts\ChangeParticipantContractTests;
use AzGuard\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

final class JournalParticipantContractTest extends TestCase
{
    use ChangeParticipantContractTests;
    use DatabaseMigrations;

    /**
     * The journal table comes from the Audit migration, as a published copy of it creates it in an application: its
     * directory joins the migration paths before `DatabaseMigrations` migrates.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->app->make('migrator')->path(dirname(__DIR__, 4).'/packages/core/modules/audit/database/migrations');
    }

    protected function azguardParticipant(): ChangeParticipant
    {
        return new JournalParticipant;
    }
}
