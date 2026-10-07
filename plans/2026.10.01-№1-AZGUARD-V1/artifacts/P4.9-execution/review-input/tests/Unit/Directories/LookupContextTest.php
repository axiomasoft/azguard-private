<?php

declare(strict_types=1);

use AzGuard\Directories\AssignmentScopeOption;
use AzGuard\Directories\LookupContext;
use AzGuard\Directories\SubjectOption;
use AzGuard\Directories\TenantOption;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;

it('keeps lookup inputs and directory options readonly', function (): void {
    $panel = PanelWorld::adminAndCabinet()[2]->get('admin');
    $tenant = TenantRef::of('crm.organization', 1);
    $scope = AssignmentScopeRef::of('crm.project', 4);
    $subject = SubjectRef::of('user', 9);
    $lookup = new LookupContext(
        panel: $panel,
        scope: AccessScope::in($tenant, $scope),
        actor: ActorRef::of('user', 2),
        actorModel: null,
        subject: null,
        user: null,
        role: null,
        proposed: ['until' => null],
        phase: AssignmentScopePhase::Assignment,
        now: new DateTimeImmutable('2026-10-03T00:00:00Z'),
    );

    expect($lookup->subject)->toBeNull()
        ->and($lookup->proposed)->toBe(['until' => null])
        ->and($lookup->phase)->toBe(AssignmentScopePhase::Assignment)
        ->and((new TenantOption($tenant, 'Acme', 'North'))->description)->toBe('North')
        ->and((new AssignmentScopeOption($scope, 'Site'))->scope)->toBe($scope)
        ->and((new SubjectOption($subject, 'Ada'))->label)->toBe('Ada')
        ->and((new ReflectionClass(LookupContext::class))->isReadOnly())->toBeTrue();
});
