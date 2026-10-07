<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;

// A third-party source records role provenance on a direct grant of one pattern; the role is a super-admin role.
function roleProvenanceGrant(): Grant
{
    return Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', AccessScope::in(TenantRef::global()), role: RoleKey::of('admin', 'root'));
}

it('A02 counts a direct grant with a super-admin role key only through its own pattern', function (): void {
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(direct: [roleProvenanceGrant()]));

    $view = $engine->decide($panel, $request);
    $edit = $engine->decide($panel, AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.edit')));

    expect($view->allowed())->toBeTrue()->and($view->reason)->toBe(DecisionReason::Granted)
        ->and($edit->allowed())->toBeFalse()->and($edit->reason)->toBe(DecisionReason::NotGranted)
        ->and($engine->isSuperAdmin($panel, $request->subject(), AccessScope::in(TenantRef::global())))->toBeFalse();
});

it('A02 does not exempt a direct grant with a super-admin role key from restrictions', function (): void {
    $restriction = new RecordingRestriction(deny: true, exempt: true);
    [$engine, $panel, $request] = AuthorizationWorld::compile(
        new GeneratedSource(direct: [roleProvenanceGrant()]),
        fn (PanelBuilder $panel) => $panel->restrictions([$restriction]),
    );

    $decision = $engine->decide($panel, $request);

    expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::Restricted);
});
