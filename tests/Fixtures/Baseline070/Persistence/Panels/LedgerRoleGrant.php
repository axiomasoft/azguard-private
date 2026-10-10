<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Baseline070\Persistence\Panels;

use AzGuard\Schema\Field;
use AzGuard\Storage\Models\RoleGrant;

/** Role grants with fields in `meta` and one application column (`ticket`) the host migration adds to the table. */
final class LedgerRoleGrant extends RoleGrant
{
    public static function azguardFields(): array
    {
        return [Field::string('region')->inMeta(), Field::bool('eligible')->inMeta(), Field::string('ticket')];
    }
}
