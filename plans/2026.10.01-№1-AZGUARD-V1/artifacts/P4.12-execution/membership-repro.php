<?php
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Scopes\Organization;
use AzGuard\Tests\Fixtures\Scopes\Membership;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Panels\PanelBuilder;
uses(AzGuard\Tests\TestCase::class);
it('denies ordinary granted visibility when fixed tenant membership is absent', function () {
 W::seed();
 $source=new VisibilitySource;
 [$visibility,$panel,$authorizer]=W::compile($source,fn(PanelBuilder $panel)=>$panel->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership(members:[]))),tenant:true);
 $source->direct=[W::grant(1)];
 $request=AccessRequest::for(W::subject(),PermissionKey::of('admin','orders.view'))->on(null,VisibilityProject::query()->find(1))->inTenant(W::tenant());
 expect($authorizer->decide($panel,$request)->allowed())->toBeFalse();
 expect($visibility->visibleTo($panel,VisibilityProject::query(),W::subject(),'orders.view',W::scope())->pluck('id')->all())->toBe([]);
 W::reset();
});
