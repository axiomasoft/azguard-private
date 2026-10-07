<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Authorization;

use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What the engine shows a source, a hook or a restriction during one check.
 *
 * @api
 */
interface EvaluationContext
{
    public function panel(): Panel;

    public function scope(): AccessScope;

    /**
     * @return list<AssignmentScopeRef>
     */
    public function scopes(): array;

    public function resource(): ?object;

    public function state(): CodeStateToken|StateToken;

    public function now(): DateTimeImmutable;

    public function subjectModel(): ?Model;

    public function actor(): ActorRef;

    public function actorModel(): ?Model;

    public function role(): ?BaseRole;

    public function grant(): Grant|RoleContribution|null;

    /**
     * Grants that cover the permission being checked.
     *
     * @return list<Grant>
     */
    public function matchingGrants(): array;
}
