<?php

declare(strict_types=1);

namespace AzGuard\Tests\Feature\Directories\Support;

use AzGuard\Directories\LookupContext;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use DateTimeImmutable;

final class Lookups
{
    /**
     * @param  class-string<BaseRole>|null  $role
     * @param  array<string, mixed>  $proposed
     */
    public static function make(
        Panel $panel,
        int $tenant = 1,
        ?int $target = 1,
        ?string $role = null,
        ?int $actor = 2,
        AssignmentScopePhase $phase = AssignmentScopePhase::Assignment,
        array $proposed = [],
    ): LookupContext {
        return new LookupContext(
            panel: $panel,
            scope: CrmWorld::scope($tenant),
            actor: $actor === null ? null : ActorRef::of('crm.user', $actor),
            actorModel: $actor === null ? null : User::query()->find($actor),
            subject: $target === null ? null : SubjectRef::of('crm.user', $target),
            user: $target === null ? null : User::query()->find($target),
            role: $role === null ? null : app($role),
            proposed: $proposed,
            phase: $phase,
            now: new DateTimeImmutable('2026-10-06T12:00:00Z'),
        );
    }
}
