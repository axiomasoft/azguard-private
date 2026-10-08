<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Filament;

/** A call to a client was recorded. */
final readonly class ClientCalled
{
    public function __construct(public int $client) {}
}
