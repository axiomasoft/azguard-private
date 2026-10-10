<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

/**
 * A service of the audit plugin, resolved by the container; plugins write what they saw to it.
 */
class AuditJournal
{
    /** @var list<array<string, mixed>> */
    public array $lines = [];
}
