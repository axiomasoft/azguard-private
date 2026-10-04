<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\DepartmentCondition;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Authorization\WeekdaysCondition;
use AzGuard\Tests\Fixtures\Authorization\WriterSource;
use AzGuard\Tests\Fixtures\Roles\RootRole;
use Illuminate\Support\Carbon;

it('qualifies department AND weekday in one contribution rather than joining two rows', function (): void {
    $source = new GeneratedSource(direct: [AuthorizationWorld::grant(['department' => 'sales', 'weekdays' => [2]]), AuthorizationWorld::grant(['department' => 'other', 'weekdays' => [1]])]);
    [$engine,$panel,$request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->grantConditions([new DepartmentCondition, new WeekdaysCondition]));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted);
    $source->direct[] = AuthorizationWorld::grant(['department' => 'sales', 'weekdays' => [1]]);
    expect($engine->decide($panel, $request)->allowed())->toBeTrue();
});
it('expires role and direct contributions exactly at now', function (bool $role): void {
    $source = new GeneratedSource(direct: $role ? [] : [AuthorizationWorld::grant(expires: Carbon::now()->toDateTimeImmutable())], roles: $role ? [RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated', expiresAt: Carbon::now()->toDateTimeImmutable())] : []);
    [$engine,$panel,$request] = AuthorizationWorld::compile($source);
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted);
})->with([false, true]);
it('does not alias former or removed role keys to a current role', function (): void {
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('admin', 'removed'), AccessScope::in(TenantRef::global()), 'folder')]));
    expect($engine->decide($panel, $request->traced())->reason)->toBe(DecisionReason::NotGranted);
});
it('foreign panel with the same local root key is source error', function (): void {
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('beta', 'root'), AccessScope::in(TenantRef::global()), 'folder')]));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SourceError);
});
it('validates malformed types and foreign scopes before allowing', function (string $kind): void {
    $bad = match ($kind) {
        'type' => new stdClass,'panel' => AuthorizationWorld::grant(panel: 'beta'),'scope' => Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', AccessScope::in(TenantRef::of('org', 9))),
    };
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant(), $bad]));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SourceError);
})->with(['type', 'panel', 'scope']);
it('superadmin conditions receive the actual code role and original contribution', function (): void {
    $contribution = RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated', fields: ['ok' => true]);
    $condition = new class implements GrantCondition
    {
        public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
        {
            if ($grant instanceof Grant) {
                return true;
            }
            expect($context->grant())->toBe($grant)->and($context->role())->toBeInstanceOf(RootRole::class)->and($context->actor()->id)->toBe('1');

            return $grant->fields()['ok'];
        }
    };
    $restriction = new RecordingRestriction(deny: true, exempt: true);
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()], roles: [$contribution]), fn (PanelBuilder $panel) => $panel->grantConditions([$condition])->restrictions([$restriction]));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SuperAdmin);
});
it('an ordinary grant does not remove a qualified superadmin exemption', function (): void {
    $restriction = new RecordingRestriction(deny: true, exempt: true);
    $admin = RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated');
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()], roles: [$admin]), fn (PanelBuilder $panel) => $panel->restrictions([$restriction]));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SuperAdmin)->and($restriction->checks)->toBe(0);
});

it('uses actual writer provenance rather than a forged folder source label for NotGrantable roles', function (): void {
    $role = RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'folder');
    [$engine,$panel,$request] = AuthorizationWorld::compile(new WriterSource(roles: [$role]));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted);
});
it('qualifies each role contribution before expanding its compiled patterns', function (): void {
    $role = new class extends BaseRole
    {
        public function key(): string
        {
            return 'reader';
        }

        public function permissions(): array
        {
            return ['orders.*'];
        }
    };
    $source = new GeneratedSource(roles: [RoleContribution::of(RoleKey::of('admin', 'reader'), AccessScope::in(TenantRef::global()), 'generated')]);
    [$engine,$panel,$request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->roles([$role::class]));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::Granted);
});
it('rejects a role contribution returned from a direct grant capability', function (): void {
    $role = RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated');
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [$role]));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SourceError);
});
it('checks superadmin expiry and conditions without exempting mandatory restrictions', function (): void {
    $admin = RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated', fields: ['weekdays' => [2]]);
    $source = new GeneratedSource(roles: [$admin]);
    [$engine,$panel,$request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->grantConditions([WeekdaysCondition::class]));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::NotGranted);
    $source->roles = [RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated', fields: ['weekdays' => [1]])];
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SuperAdmin);
});
