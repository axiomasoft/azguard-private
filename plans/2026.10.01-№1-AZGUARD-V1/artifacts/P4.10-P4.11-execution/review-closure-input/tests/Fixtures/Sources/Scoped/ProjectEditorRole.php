<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Scoped;

use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Sources\Relation\ProjectDefinition;

#[Role('editor')]
final class ProjectEditorRole extends BaseRole
{
    public function permissions(): array
    {
        return ['projects.view', 'projects.edit'];
    }

    public function scopes(): array
    {
        return [new ProjectDefinition];
    }
}
