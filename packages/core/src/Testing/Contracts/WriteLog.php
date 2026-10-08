<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

/**
 * @internal The statements that change data which a suite saw while it watched.
 */
final class WriteLog
{
    public bool $active = true;

    /** @var list<string> */
    public array $writes = [];
}
