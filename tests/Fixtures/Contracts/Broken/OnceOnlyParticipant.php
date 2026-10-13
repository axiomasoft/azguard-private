<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\OwnedRowsWriter;

/** A participant that remembers it already wrote and skips the retried attempt, whose rows are then missing. */
final class OnceOnlyParticipant implements ChangeParticipant
{
    public static bool $written = false;

    public function tables(): array
    {
        return ['contract_rows'];
    }

    public function record(array $records, OwnedRowsWriter $rows): void
    {
        if (! self::$written) {
            self::$written = true;
            $rows->insert('contract_rows', [['note' => 'once']]);
        }
    }
}
