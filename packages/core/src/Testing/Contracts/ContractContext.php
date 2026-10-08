<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal What the engine gives a source for one read, fixed by the suite: the panel, the scope and one `now`.
 */
final readonly class ContractContext implements EvaluationContext
{
    public function __construct(private Panel $panel, private AccessScope $scope, private DateTimeImmutable $now) {}

    public function panel(): Panel
    {
        return $this->panel;
    }

    public function scope(): AccessScope
    {
        return $this->scope;
    }

    public function scopes(): array
    {
        return [$this->scope->context];
    }

    public function resource(): ?object
    {
        return null;
    }

    public function state(): CodeStateToken
    {
        return CodeStateToken::of($this->panel->id(), 'contract', 'contract');
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function subjectModel(): ?Model
    {
        return null;
    }

    public function actor(): ActorRef
    {
        return ActorRef::system('contract');
    }

    public function actorModel(): ?Model
    {
        return null;
    }

    public function role(): ?BaseRole
    {
        return null;
    }

    public function grant(): Grant|RoleContribution|null
    {
        return null;
    }

    public function matchingGrants(): array
    {
        return [];
    }
}
