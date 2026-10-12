<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Attributes\Api;
use AzGuard\Context\AssignmentScopePhase;
use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\AssignmentScopeDirectory;
use AzGuard\Contracts\Subjects\SubjectDirectory;
use AzGuard\Contracts\Tenancy\TenantDirectory;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Roles\BaseRole;
use Illuminate\Contracts\Container\Container;

/**
 * The directories of one panel for an interface: who can be chosen as a subject, a tenant or an assignment scope.
 *
 * Each is the directory declared in code for the panel (`for(..., directory:)`, the directory of an assignment scope),
 * otherwise the default one of that kind; the schema of the panel names the same classes. A directory only searches and
 * describes; it decides neither membership nor access, and a caller checks every chosen value with `describe()` again
 * before it writes.
 */
#[Api(since: '0.7')]
final readonly class PanelDirectories
{
    private DirectoryResolver $resolver;

    /**
     * @internal made by the panel access
     */
    public function __construct(Panel $panel, private Container $container)
    {
        $this->resolver = DirectoryResolver::for($panel, $container);
    }

    /**
     * The subjects of one morph type, or of every subject model of the panel without a type; an unknown type has none.
     *
     * @throws DefinitionException when a declared directory does not implement the contract
     */
    public function subjects(?string $type = null): SubjectDirectory
    {
        return $this->resolver->subjects($type);
    }

    /**
     * The tenants of the panel; a panel without a tenant model has none.
     */
    public function tenants(): TenantDirectory
    {
        return $this->resolver->tenants();
    }

    /**
     * The assignment scopes of one type. In the Assignment phase it offers and describes only what the write would
     * accept for the target of the lookup: the tenant, scope mode, declared type and role checks of the write run
     * once per lookup before the declared directory searches, and a described context also passes the structural
     * and evaluator checks of the write.
     *
     * @throws DefinitionException when a declared directory does not implement the contract
     */
    public function scopes(string $type): AssignmentScopeDirectory
    {
        return new readonly class($this->resolver->scopes($type), $this->container) implements AssignmentScopeDirectory
        {
            public function __construct(private AssignmentScopeDirectory $directory, private Container $container) {}

            public function search(string $type, string $term, LookupContext $lookup, int $limit): array
            {
                return $this->admits($type, $lookup) ? $this->directory->search($type, $term, $lookup, $limit) : [];
            }

            public function describe(AssignmentScopeRef $context, LookupContext $lookup): ?AssignmentScopeOption
            {
                if ($lookup->phase === AssignmentScopePhase::Assignment && ($context->isGlobal() || ! $this->admits($context->type() ?? '', $lookup)
                    || ! $this->admitsContext($context, $lookup))) {
                    return null;
                }

                return $this->directory->describe($context, $lookup);
            }

            /** The checks of the write that do not depend on the context: tenant, target membership, scope mode, type and role. */
            private function admits(string $type, LookupContext $lookup): bool
            {
                if ($lookup->phase !== AssignmentScopePhase::Assignment) {
                    return true;
                }
                $role = $this->role($lookup);

                if ($lookup->subject === null || $lookup->panel->scopeDefinition($type) === null || ($lookup->role !== null && $role === null)) {
                    return false;
                }

                try {
                    DirectoryResolver::admitTenancy($lookup->panel, $this->container, $lookup->scope->tenant, false, $lookup->subject, $role['class'] ?? null);
                } catch (AzGuardException) {
                    return false;
                }

                return $role === null || in_array($type, array_column($role['scopes'], 'type'), true);
            }

            private function admitsContext(AssignmentScopeRef $context, LookupContext $lookup): bool
            {
                $role = $this->role($lookup);

                try {
                    DirectoryResolver::admitContext(new AssignmentScopeRuntime(panel: $lookup->panel, scope: AccessScope::in($lookup->scope->tenant, $context),
                        subject: $lookup->subject ?? throw new SubjectNotAcceptedException('An assignment lookup needs a target subject.'), user: $lookup->user,
                        role: $lookup->role, grant: null, actor: $lookup->actor, actorModel: $lookup->actorModel, now: $lookup->now, phase: $lookup->phase,
                        proposed: $lookup->proposed), $role === null ? null : array_column($role['scopes'], 'type'), $lookup->role?->key(), $this->container);
                } catch (AzGuardException) {
                    return false;
                }

                return true;
            }

            /** @return array{class: class-string<BaseRole>, scopes: list<array{type: string}>}|null the compiled role of the lookup */
            private function role(LookupContext $lookup): ?array
            {
                return $lookup->role === null ? null
                    : $this->container->make(PanelRegistry::class)->catalog($lookup->panel->id())->roles()[$lookup->role->key()] ?? null;
            }
        };
    }
}
