<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\OwnedRowsWriter;

/** A participant that also writes on a connection no transaction of the operation covers. */
final class LeakyParticipant implements ChangeParticipant
{
    public function tables(): array
    {
        return ['contract_rows'];
    }

    public function record(array $records, OwnedRowsWriter $rows): void
    {
        $rows->insert('contract_rows', [['note' => 'own']]);
        app('db')->connection('secondary')->table('contract_leak')->insert(['note' => 'leak']);
    }
}
