<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Consumer;

use Acme\Consumer\ConsumerParticipant;
use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Testing\Contracts\ChangeParticipantContractTests;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;

require_once dirname(__DIR__, 2).'/Fixtures/Modules/Consumer/autoload.php';

/** I8, I11: the participant of a package outside `AzGuard\` passes the participant suite on its own table. */
final class ConsumerParticipantContractTest extends TestCase
{
    use ChangeParticipantContractTests;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('azg_consumer_rows', static function (Blueprint $table): void {
            $table->id();
            $table->string('note');
        });
    }

    protected function azguardParticipant(): ChangeParticipant
    {
        return new ConsumerParticipant;
    }
}
