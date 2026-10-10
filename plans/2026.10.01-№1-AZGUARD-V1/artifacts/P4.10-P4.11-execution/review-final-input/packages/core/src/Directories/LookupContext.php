<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePhase;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable inputs of a directory search. `proposed` holds assignment fields, not role configuration.
 *
 * The target subject may be absent while the caller is still choosing who to assign.
 *
 * @api
 */
final readonly class LookupContext
{
    /**
     * @param  array<string, mixed>  $proposed
     */
    public function __construct(
        public Panel $panel,
        public AccessScope $scope,
        public ActorRef $actor,
        public ?Model $actorModel,
        public ?SubjectRef $subject,
        public ?Model $user,
        public ?BaseRole $role,
        public array $proposed,
        public AssignmentScopePhase $phase,
        public DateTimeImmutable $now,
    ) {}
}
