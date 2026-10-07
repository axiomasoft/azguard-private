<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm\Resolvers;

use AzGuard\Contracts\Scopes\ResourceScopeResolver;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use RuntimeException;

final class ClientScopeResolver implements ResourceScopeResolver
{
    public static bool $throws = false;

    public function resolve(object $resource, ?AccessScope $selected = null): AccessScope
    {
        if (self::$throws) {
            throw new RuntimeException('client resolver unavailable');
        }

        if (! $resource instanceof Client) {
            throw new RuntimeException('Expected Client');
        }

        return AccessScope::in(TenantRef::of('crm.organization', (string) $resource->getAttribute('organization_id')), AssignmentScopeRef::of('crm.project', (string) $resource->getAttribute('project_id')));
    }
}
