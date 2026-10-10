<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Guards\Crm;

use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Panels\StateRefresh;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Filters\ActiveProjects;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Resolvers\ClientScopeResolver;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Restrictions\AccountLockedRestriction;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\User;

final class CrmGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'crm';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        $panel->for(model: User::class, guard: 'web')->permissions(CrmWorld::$sources)
            ->tenants(TenantPolicy::required(Organization::class)->requireMembership(new OrganizationMembership))
            ->scopes(AssignmentScopePolicy::inherit(ProjectScope::make()->filter(new ActiveProjects)))
            ->resourceScopes([Client::class => new ClientScopeResolver])
            ->restrictions([new AccountLockedRestriction])->grantConditions([new RegionCondition])
            ->consistency(refresh: StateRefresh::Check);

        if (CrmWorld::$configure !== null) {
            (CrmWorld::$configure)($panel);
        }

        return $panel;
    }
}
