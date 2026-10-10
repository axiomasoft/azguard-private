<?php

declare(strict_types=1);

namespace AzGuard\Scopes;

use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable inputs of one assignment-scope operation. The frame does not read Auth or the container.
 *
 * `user` is the target subject when it resolved, not the implicit authenticated user. `role` is null for a
 * common, direct or policy-only check.
 *
 * @api
 */
final readonly class AssignmentScopeRuntime
{
    public function __construct(
        public Panel $panel,
        public AccessScope $scope,
        public SubjectRef $subject,
        public ?Model $user,
        public ?BaseRole $role,
        public Grant|RoleContribution|null $grant,
        public ActorRef $actor,
        public ?Model $actorModel,
        public DateTimeImmutable $now,
        public AssignmentScopePhase $phase,
    ) {}
}
