<?php

declare(strict_types=1);

namespace AzGuard\Concerns;

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\Visibility;
use AzGuard\Changes\ChangePipeline;
use AzGuard\Changes\PanelManagers;
use AzGuard\Contracts\Catalog\PermissionCatalog;
use AzGuard\Contracts\Changes\GrantManager;
use AzGuard\Contracts\Changes\PermissionManager;
use AzGuard\Contracts\PanelAccess;
use AzGuard\Contracts\Roles\RoleCatalog;
use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Directories\PanelDirectories;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionSet;
use AzGuard\Kernel\Decision\Explanation;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Schema\PanelSchema;
use AzGuard\Schema\SchemaBuilder;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Scopes\ModelIdentity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The panel access of `AzGuard::panel()`: the panel, the tenant and the origin are fixed in the constructor and
 * every method works inside that partition. It forwards to the engine, the managers and the schema builder that
 * already exist and decides nothing itself.
 *
 * The tenant is the one given to `inTenant()`, otherwise the current tenant of the panel, otherwise the global tenant
 * of a panel without tenants; it is resolved at the call that needs it, never stored from the request.
 */
final readonly class ScopedPanelAccess implements PanelAccess
{
    /**
     * @throws TenantMismatchException when the tenant is not of the tenant type of the panel
     */
    public function __construct(
        private Panel $panel,
        private ?TenantRef $tenant = null,
        private string $origin = IdentityCodec::DEFAULT_ORIGIN,
    ) {
        IdentityCodec::assertSourceLabel($origin);

        if ($tenant !== null && ! $tenant->isGlobal() && $tenant->type() !== $panel->tenants()->definition()?->type()) {
            throw new TenantMismatchException('Panel '.$panel->id().' does not take tenants of the type "'.$tenant->type().'".');
        }
    }

    public function definition(): Panel
    {
        return $this->panel;
    }

    public function inTenant(Model|TenantRef $tenant): self
    {
        return new self($this->panel, $tenant instanceof TenantRef ? $tenant : ModelIdentity::tenant($this->panel, $tenant), $this->origin);
    }

    public function fromOrigin(string $origin): self
    {
        return new self($this->panel, $this->tenant, $origin);
    }

    public function scope(): AccessScope
    {
        return AccessScope::in($this->requiredTenant());
    }

    public function for(Model|Authenticatable|SubjectRef $subject): SubjectAccess
    {
        if (! $subject instanceof Model && ! $subject instanceof SubjectRef) {
            throw new SubjectNotAcceptedException($subject::class.' is not an Eloquent model: panel "'.$this->panel->id().'" takes models and subject references.');
        }

        return new SubjectAccess($this->panel, $subject, $this->tenant, $this->origin);
    }

    public function roles(): RoleCatalog
    {
        return PanelManagers::for($this->panel, $this->tenant ?? TenantRef::global(), $this->origin)->roles();
    }

    public function grants(): GrantManager
    {
        return $this->managers()->grants();
    }

    public function permissions(): PermissionManager
    {
        return $this->managers()->permissions();
    }

    public function schema(): PanelSchema
    {
        return app(SchemaBuilder::class)->for($this->panel, $this->requiredTenant());
    }

    public function decide(AccessRequest $request): Decision
    {
        return app(Authorizer::class)->decide($this->panel, $this->own($request));
    }

    public function decideMany(iterable $requests): DecisionSet
    {
        $own = [];
        foreach ($requests as $request) {
            $own[] = $this->own($request);
        }

        return app(Authorizer::class)->decideMany($own);
    }

    public function explain(AccessRequest $request): Explanation
    {
        return app(Authorizer::class)->explain($this->panel, $this->own($request));
    }

    public function catalog(): PermissionCatalog
    {
        return app(PanelRegistry::class)->catalog($this->panel->id());
    }

    public function visibility(): Visibility
    {
        return app(Visibility::class);
    }

    public function directories(): PanelDirectories
    {
        return new PanelDirectories($this->panel, app());
    }

    public function state(): StateToken
    {
        $writer = $this->panel->writer();

        if (! $writer instanceof FencesReads) {
            throw new PanelNotWritableException('Panel '.$this->panel->id().' has no writer that keeps a state.');
        }

        return $writer->state($this->panel, $this->requiredTenant());
    }

    public function touch(): StateToken
    {
        return app(ChangePipeline::class)->touch($this->panel, 'manual');
    }

    /**
     * The request as this access may ask it: for this panel, and for the tenant of the access when it has one given.
     *
     * @throws ConflictingPanelException
     * @throws TenantMismatchException
     */
    private function own(AccessRequest $request): AccessRequest
    {
        if ($request->permission()->panel() !== $this->panel->id()) {
            throw new ConflictingPanelException('The access is for panel "'.$this->panel->id().'", the request is for "'.$request->permission()->panel().'".');
        }

        $tenant = $this->tenant ?? app(CurrentContext::class)->get($this->panel)?->tenant;

        if ($tenant === null) {
            return $request;
        }
        $asked = $request->tenant();

        if ($asked === null) {
            return $request->inTenant($tenant);
        }

        if (! $asked->equals($tenant)) {
            throw new TenantMismatchException('The access is for tenant "'.$tenant->key().'", the request is for "'.$asked->key().'".');
        }

        return $request;
    }

    private function managers(): PanelManagers
    {
        return PanelManagers::for($this->panel, $this->requiredTenant(), $this->origin);
    }

    /** @throws TenantRequiredException */
    private function requiredTenant(): TenantRef
    {
        $tenant = $this->tenant ?? app(CurrentContext::class)->get($this->panel)?->tenant;

        if ($tenant !== null) {
            return $tenant;
        }

        if ($this->panel->tenants()->mode() === 'none') {
            return TenantRef::global();
        }

        throw new TenantRequiredException('Panel '.$this->panel->id().' has tenants: choose one with inTenant(); nothing is read or changed across tenants.');
    }
}
