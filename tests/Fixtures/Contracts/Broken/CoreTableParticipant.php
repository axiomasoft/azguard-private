<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Contracts\Broken;

use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\OwnedRowsWriter;

/** A participant that claims an authority table of the core. */
final class CoreTableParticipant implements ChangeParticipant
{
    public function tables(): array
    {
        return ['role_grants'];
    }

    public function record(array $records, OwnedRowsWriter $rows): void {}
}
