<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Changes;

use AzGuard\Audit\Internal\JournalParticipant;
use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Testing\Contracts\ChangeParticipantContractTests;
use AzGuard\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

final class JournalParticipantContractTest extends TestCase
{
    use ChangeParticipantContractTests;
    use DatabaseMigrations;

    protected function azguardParticipant(): ChangeParticipant
    {
        return new JournalParticipant;
    }
}
