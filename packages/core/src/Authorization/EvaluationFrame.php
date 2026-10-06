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
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Roles\BaseRole;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/** Immutable operation inputs; a contribution receives its own frame. */
final readonly class EvaluationFrame implements EvaluationContext
{
    /** @param list<Grant> $matching
     * @param  array<string, StateToken>  $sourceStates
     */
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
        public ?ReadAttempt $readAttempt = null,
        public array $sourceStates = [],
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
        $contexts = [];
        foreach ($this->sourceScopes() as $scope) {
            $contexts[$scope->context->key()] = $scope->context;
        }

        return array_values($contexts);
    }

    /** @return list<AccessScope> */
    public function sourceScopes(): array
    {
        $scopes = $this->selectedScope->context->isGlobal() || $this->selectedPanel->scopes()->mode() === 'isolated'
            ? [$this->selectedScope]
            : [AccessScope::in($this->selectedScope->tenant), $this->selectedScope];

        if (! $this->selectedScope->tenant->isGlobal() && $this->selectedPanel->tenants()->globalRoles() !== []) {
            foreach ($scopes as $scope) {
                $scopes[] = AccessScope::in(tenant: TenantRef::global(), context: $scope->context);
            }
        }

        return $scopes;
    }

    public function acceptsContributionScope(AccessScope $scope): bool
    {
        foreach ($this->sourceScopes() as $accepted) {
            if ($accepted->equals($scope)) {
                return true;
            }
        }

        return false;
    }

    public function withScope(AccessScope $scope): self
    {
        return new self($this->selectedPanel, $scope, $this->token, $this->decisionNow, $this->selectedActor, $this->subject, $this->actorSubject, $this->selectedResource, $this->selectedRole, $this->contribution, $this->matching, $this->qualifiedSuperAdmin, $this->readAttempt, $this->sourceStates);
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
        return new self($this->selectedPanel, $this->selectedScope, $this->token, $this->decisionNow, $this->selectedActor, $this->subject, $this->actorSubject, $this->selectedResource, $role, $grant, $this->matching, $this->qualifiedSuperAdmin, $this->readAttempt, $this->sourceStates);
    }

    public function withState(CodeStateToken|StateToken $state): self
    {
        return new self($this->selectedPanel, $this->selectedScope, $state, $this->decisionNow, $this->selectedActor, $this->subject, $this->actorSubject, $this->selectedResource, $this->selectedRole, $this->contribution, $this->matching, $this->qualifiedSuperAdmin, $this->readAttempt, $this->sourceStates);
    }

    public function withReadAttempt(ReadAttempt $attempt): self
    {
        return new self($this->selectedPanel, $this->selectedScope, $this->token, $this->decisionNow, $this->selectedActor, $this->subject, $this->actorSubject, $this->selectedResource, $this->selectedRole, $this->contribution, $this->matching, $this->qualifiedSuperAdmin, $attempt, $this->sourceStates);
    }

    /** @param array<string, StateToken> $states */
    public function withSourceStates(array $states, ?StateToken $database = null): self
    {
        return new self($this->selectedPanel, $this->selectedScope, $database ?? $this->token, $this->decisionNow, $this->selectedActor, $this->subject, $this->actorSubject, $this->selectedResource, $this->selectedRole, $this->contribution, $this->matching, $this->qualifiedSuperAdmin, $this->readAttempt, $states);
    }

    /** @param list<Grant> $grants */
    public function withAuthority(array $grants, bool $superAdmin): self
    {
        return new self($this->selectedPanel, $this->selectedScope, $this->token, $this->decisionNow, $this->selectedActor, $this->subject, $this->actorSubject, $this->selectedResource, null, null, $grants, $superAdmin, $this->readAttempt, $this->sourceStates);
    }
}
