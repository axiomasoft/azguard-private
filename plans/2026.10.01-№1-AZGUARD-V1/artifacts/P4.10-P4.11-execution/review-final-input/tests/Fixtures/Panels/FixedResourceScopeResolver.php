<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Panels;

use AzGuard\Contracts\Scopes\ResourceScopeResolver;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;

final class FixedResourceScopeResolver implements ResourceScopeResolver
{
    public function resolve(object $resource, ?AccessScope $selected = null): AccessScope
    {
        return $selected ?? AccessScope::in(TenantRef::global(), AssignmentScopeRef::global());
    }
}
