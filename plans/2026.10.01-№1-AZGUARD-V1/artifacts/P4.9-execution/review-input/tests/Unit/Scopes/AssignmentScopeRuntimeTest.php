<?php

declare(strict_types=1);

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\AssignmentScopeRuntime;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;

it('keeps the operation frame a readonly value with a nullable role and user', function (): void {
    $panel = PanelWorld::adminAndCabinet()[2]->get('admin');
    $now = new DateTimeImmutable('2026-10-03T00:00:00Z');
    $runtime = new AssignmentScopeRuntime(
        panel: $panel,
        scope: AccessScope::in(TenantRef::of('crm.organization', 1)),
        subject: SubjectRef::of('user', 1),
        user: null,
        role: null,
        grant: null,
        actor: ActorRef::of('user', 2),
        actorModel: null,
        now: $now,
        phase: AssignmentScopePhase::Access,
    );

    expect($runtime->panel)->toBe($panel)
        ->and($runtime->user)->toBeNull()
        ->and($runtime->role)->toBeNull()
        ->and($runtime->grant)->toBeNull()
        ->and($runtime->phase)->toBe(AssignmentScopePhase::Access)
        ->and($runtime->now)->toBe($now)
        ->and((new ReflectionClass(AssignmentScopeRuntime::class))->isReadOnly())->toBeTrue();
});
