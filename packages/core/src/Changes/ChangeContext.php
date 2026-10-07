<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePhase;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Checked inputs of one change, derived again for every change value and every attempt.
 *
 * `user` is the target subject, never the actor. `actor` is null when the change has no actor. `proposed` holds only
 * schema-validated `until` and `fields` of a grant or an update; it is empty for a revocation. `state` is the locked
 * panel state before this operation.
 *
 * @api
 */
final readonly class ChangeContext
{
    /**
     * @internal built by the change pipeline
     *
     * @param  array<string, mixed>  $proposed
     */
    public function __construct(
        public Panel $panel,
        public AccessScope $scope,
        public ?ActorRef $actor,
        public ?Model $actorModel,
        public ?SubjectRef $subject,
        public ?Model $user,
        public ?BaseRole $role,
        public ChangeType $operation,
        public AssignmentScopePhase $phase,
        public array $proposed,
        public DateTimeImmutable $now,
        public StateToken $state,
    ) {}
}
