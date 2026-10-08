<?php

declare(strict_types=1);

namespace AzGuard\Filament\Editors;

use AzGuard\Contracts\PanelAccess;
use AzGuard\Directories\LookupContext;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Authorization\FilamentContext;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Scopes\CurrentContext;
use Closure;
use DateTimeImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The target of an editor: the AzGuard panel it reads or changes and the tenant inside it.
 *
 * A panel is offered when the plugin manages it (every panel when `manages` is null) and it stores grants. The tenant
 * of the guard panel is the tenant of the request and is never chosen; a panel without tenants is global; in any other
 * panel the tenant is searched in the tenant directory of that panel. The values come from a Livewire payload, so
 * `access()` checks the panel and the tenant again on every call and refuses anything it would not offer.
 *
 * @internal
 */
final readonly class TargetSelector
{
    /** How many tenants a search offers. */
    public const int LIMIT = 50;

    /**
     * @param  (Closure(Panel): bool)|null  $accepts  a further condition on a panel, such as dynamic permissions
     */
    private function __construct(private FilamentContext $context, private ?Closure $accepts) {}

    /**
     * @param  (Closure(Panel): bool)|null  $accepts
     *
     * @throws InvalidConfigurationException when the current Filament panel has no plugin
     */
    public static function current(?Closure $accepts = null): self
    {
        $context = FilamentContext::current() ?? throw InvalidConfigurationException::failing('filament', 'The editors of AzGuard need a Filament panel with AzGuardPlugin.');

        return new self($context, $accepts);
    }

    /**
     * The panels the editor offers, by id.
     *
     * @return array<string, string>
     */
    public function panels(): array
    {
        $manages = $this->context->plugin->getManages();
        $options = [];

        foreach (AzGuard::panels() as $id => $panel) {
            if (($manages === null || in_array($id, $manages, true)) && $panel->isWritable() && ($this->accepts === null || ($this->accepts)($panel))) {
                $options[$id] = $panel->label();
            }
        }

        return $options;
    }

    /** The first panel the editor offers, or null when it offers none. */
    public function defaultPanel(): ?string
    {
        return array_key_first($this->panels());
    }

    /**
     * Whether the tenant of a panel is chosen in the editor; the guard panel takes the tenant of the request, a panel
     * without tenants has the global one.
     */
    public function choosesTenant(?string $panel): bool
    {
        $definition = $this->offered($panel);

        return $definition !== null && $definition->id() !== $this->context->guardPanel() && $definition->tenants()->definition() !== null;
    }

    /**
     * The tenants of a panel that match the term, by id; nothing for a panel whose tenant is not chosen.
     *
     * @return array<string, string>
     */
    public function searchTenants(?string $panel, string $term): array
    {
        if (! $this->choosesTenant($panel)) {
            return [];
        }
        $access = AzGuard::panel((string) $panel);
        $options = [];

        foreach ($access->directories()->tenants()->search($term, $this->lookup($access->definition()), self::LIMIT) as $option) {
            $options[(string) $option->tenant->id()] = $option->label;
        }

        return $options;
    }

    /** The label of a chosen tenant, or null when the directory does not know it. */
    public function tenantLabel(?string $panel, ?string $tenant): ?string
    {
        $ref = $this->choosesTenant($panel) ? $this->tenantRef((string) $panel, $tenant) : null;

        return $ref === null ? null : AzGuard::panel((string) $panel)->directories()->tenants()->describe($ref, $this->lookup($this->offered($panel)))?->label;
    }

    /**
     * The access to the target, after the panel and the tenant are checked again.
     *
     * @throws HttpException 403 when the panel is not offered, the tenant is not known to the directory of the panel, or
     *                       a tenant is given where the editor does not choose one
     */
    public function access(?string $panel, ?string $tenant = null): PanelAccess
    {
        $definition = $this->offered($panel) ?? self::refuse('The panel is not edited here.');
        $access = AzGuard::panel($definition->id());

        if (! $this->choosesTenant($panel)) {
            if ($tenant !== null && $tenant !== '' && $tenant !== $this->fixedTenant($definition)?->id()) {
                self::refuse('The tenant of this panel is not chosen here.');
            }

            return $definition->id() === $this->context->guardPanel() ? $access : $access->inTenant(TenantRef::global());
        }
        $ref = $this->tenantRef($definition->id(), $tenant);

        if ($ref === null || $access->directories()->tenants()->describe($ref, $this->lookup($definition)) === null) {
            self::refuse('The tenant is not known to the panel.');
        }

        return $access->inTenant($ref);
    }

    /**
     * The access to a checked panel in the global tenant, for what is defined in code and belongs to no tenant: code
     * roles and the permissions of enums.
     *
     * @throws HttpException 403 when the panel is not offered
     */
    public function definitions(?string $panel): PanelAccess
    {
        $definition = $this->offered($panel) ?? self::refuse('The panel is not edited here.');

        return AzGuard::panel($definition->id())->inTenant(TenantRef::global());
    }

    private function offered(?string $panel): ?Panel
    {
        return $panel !== null && array_key_exists($panel, $this->panels()) ? AzGuard::panels()[$panel] : null;
    }

    /** The tenant of the request in the guard panel, the global one elsewhere. */
    private function fixedTenant(Panel $panel): ?TenantRef
    {
        return $panel->id() === $this->context->guardPanel() ? app(CurrentContext::class)->get($panel)?->tenant : null;
    }

    private function tenantRef(string $panel, ?string $tenant): ?TenantRef
    {
        $type = AzGuard::panels()[$panel]->tenants()->definition()?->type();

        if ($type === null || $tenant === null || $tenant === '') {
            return null;
        }

        try {
            return TenantRef::of($type, $tenant);
        } catch (InvalidIdentityException) {
            return null;
        }
    }

    /** What a directory search knows: the target panel and the user who edits; no target subject yet. */
    private function lookup(?Panel $panel): LookupContext
    {
        $panel ??= self::refuse('The panel is not edited here.');
        $user = Filament::auth()->user();
        $actor = $user instanceof Model ? $user : null;

        return new LookupContext(
            panel: $panel,
            scope: AccessScope::in(TenantRef::global()),
            actor: $actor === null ? null : ActorRef::of($actor->getMorphClass(), $actor->getKey()),
            actorModel: $actor,
            subject: null,
            user: null,
            role: null,
            proposed: [],
            phase: AssignmentScopePhase::Assignment,
            now: new DateTimeImmutable,
        );
    }

    private static function refuse(string $message): never
    {
        abort(403, $message);
    }
}
