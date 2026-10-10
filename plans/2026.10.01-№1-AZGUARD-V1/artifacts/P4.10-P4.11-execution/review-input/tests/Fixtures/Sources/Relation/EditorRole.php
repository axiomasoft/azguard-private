<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Relation;

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;

#[Role('editor')]
final class EditorRole extends BaseRole
{
    /** @var list<AssignmentScopeDefinition> */
    public static array $definitions = [];

    public function permissions(): array
    {
        return [RelationPermission::View, RelationPermission::Edit];
    }

    public function scopes(): array
    {
        return self::$definitions;
    }
}
