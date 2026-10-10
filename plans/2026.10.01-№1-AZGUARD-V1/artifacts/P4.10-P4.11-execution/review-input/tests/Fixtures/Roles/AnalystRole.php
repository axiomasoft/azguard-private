<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Roles;

use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Scopes\ProjectScope;

/**
 * Declares everything by method overrides, without attributes.
 */
final class AnalystRole extends BaseRole
{
    public function key(): string
    {
        return 'analyst';
    }

    public function label(): string
    {
        return 'Analyst';
    }

    public function level(): int
    {
        return 10;
    }

    public function formerKeys(): array
    {
        return ['data-analyst'];
    }

    public function permissions(): array
    {
        return ['reports.*'];
    }

    public function scopes(): array
    {
        return [ProjectScope::class];
    }
}
