<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;

final class TenantDynamicSource extends GeneratedSource
{
    public ?TenantRef $observedTenant = null;

    public array $observedScopes = [];

    public function isDynamic(): bool
    {
        return true;
    }

    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
    {
        $this->observedTenant = $tenant;

        if ($tenant?->equals(TenantRef::of('org', 'A'))) {
            yield new PermissionDefinition('orders.dynamic', PermissionAuthority::Grants);
        }
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $this->observedScopes = $scopes;
        yield from $this->direct;
    }
}
