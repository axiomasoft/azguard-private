<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Contracts\Authorization\EvaluationContext;
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

/** Immutable operation inputs; a contribution receives its own frame. */
final readonly class EvaluationFrame implements EvaluationContext
{
    /** @param list<Grant> $matching */
    public function __construct(
        private Panel $selectedPanel,
        private AccessScope $selectedScope,
        private CodeStateToken|StateToken $token,
        private DateTimeImmutable $decisionNow,
        private ActorRef $selectedActor,
        private ?Model $subject = null,
        private ?Model $actorSubject = null,
        private ?object $selectedResource = null,
        private ?BaseRole $selectedRole = null,
        private Grant|RoleContribution|null $contribution = null,
        private array $matching = [],
        public bool $qualifiedSuperAdmin = false,
    ) {}

    public function panel(): Panel
    {
        return $this->selectedPanel;
    }

    public function scope(): AccessScope
    {
        return $this->selectedScope;
    }

    /** @return list<AssignmentScopeRef> */
    public function scopes(): array
    {
        return [$this->selectedScope->context];
    }

    public function resource(): ?object
    {
        return $this->selectedResource;
    }

    public function state(): CodeStateToken|StateToken
    {
        return $this->token;
    }

    public function now(): DateTimeImmutable
    {
        return $this->decisionNow;
    }

    public function subjectModel(): ?Model
    {
        return $this->subject;
    }

    public function actor(): ActorRef
    {
        return $this->selectedActor;
    }

    public function actorModel(): ?Model
    {
        return $this->actorSubject;
    }

    public function role(): ?BaseRole
    {
        return $this->selectedRole;
    }

    public function grant(): Grant|RoleContribution|null
    {
        return $this->contribution;
    }

    /** @return list<Grant> */
    public function matchingGrants(): array
    {
        return $this->matching;
    }

    public function forContribution(Grant|RoleContribution $grant, ?BaseRole $role = null): self
    {
        return new self($this->selectedPanel, $this->selectedScope, $this->token, $this->decisionNow, $this->selectedActor, $this->subject, $this->actorSubject, $this->selectedResource, $role, $grant, $this->matching, $this->qualifiedSuperAdmin);
    }

    public function withState(CodeStateToken|StateToken $state): self
    {
        return new self($this->selectedPanel, $this->selectedScope, $state, $this->decisionNow, $this->selectedActor, $this->subject, $this->actorSubject, $this->selectedResource, $this->selectedRole, $this->contribution, $this->matching, $this->qualifiedSuperAdmin);
    }

    /** @param list<Grant> $grants */
    public function withAuthority(array $grants, bool $superAdmin): self
    {
        return new self($this->selectedPanel, $this->selectedScope, $this->token, $this->decisionNow, $this->selectedActor, $this->subject, $this->actorSubject, $this->selectedResource, null, null, $grants, $superAdmin);
    }
}
