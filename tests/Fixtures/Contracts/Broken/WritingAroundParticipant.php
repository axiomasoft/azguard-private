<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\OwnedRowsWriter;

/** A participant that writes its table itself instead of through the writer it receives. */
final class WritingAroundParticipant implements ChangeParticipant
{
    public function tables(): array
    {
        return ['contract_rows'];
    }

    public function record(array $records, OwnedRowsWriter $rows): void
    {
        app('db')->table('azg_contract_rows')->insert(['note' => 'around']);
    }
}
