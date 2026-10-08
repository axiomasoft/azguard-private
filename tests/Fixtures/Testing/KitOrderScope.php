<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Testing;

use AzGuard\Contracts\Scopes\ResourceScopeResolver;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use RuntimeException;

final class KitOrderScope implements ResourceScopeResolver
{
    public function resolve(object $resource, ?AccessScope $selected = null): AccessScope
    {
        $resource instanceof KitOrder || throw new RuntimeException('Expected KitOrder');

        return AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('store', (string) $resource->getAttribute('store_id')));
    }
}
