<?php

declare(strict_types=1);

namespace AzGuard\Tests\Feature\Changes\Managers;

use AzGuard\Roles\Attributes\FormerKeys;
use AzGuard\Roles\Attributes\NotGrantable;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

/** A renamed role that is assigned only by a rule: its former grants are never migrated into storage. */
#[Role('legacy-root')]
#[FormerKeys('old-root')]
#[NotGrantable]
final class LegacyRootRole extends BaseRole
{
    public function permissions(): array
    {
        return [];
    }
}
