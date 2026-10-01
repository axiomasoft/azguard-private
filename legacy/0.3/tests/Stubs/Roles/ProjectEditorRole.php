<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Roles;

use AzGuard\Roles\BaseRole;

class ProjectEditorRole extends BaseRole
{
    public function permissions(): array
    {
        return ['projects.edit', 'projects.view'];
    }
}
