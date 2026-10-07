<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Catalog\CodeRoleCatalog;
use AzGuard\Contracts\Changes\GrantManager;
use AzGuard\Contracts\Changes\PermissionManager;
use AzGuard\Contracts\Roles\RoleCatalog;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Schema\SchemaBuilder;
use Illuminate\Contracts\Container\Container;

/**
 * @internal The managers of one panel, tenant and origin. The partition is fixed here and nowhere else: the managers
 * it makes take ids, filters and details, never a panel, a tenant or an origin.
 */
final readonly class PanelManagers
{
    private function __construct(private Container $container, private Panel $panel, private TenantRef $tenant, private string $origin) {}

    /**
     * @throws TenantMismatchException when the tenant is not of the tenant type of the panel
     */
    public static function for(Panel $panel, TenantRef $tenant, string $origin = IdentityCodec::DEFAULT_ORIGIN, ?Container $container = null): self
    {
        IdentityCodec::assertSourceLabel($origin);
        $type = $panel->tenants()->definition()?->type();

        if (! $tenant->isGlobal() && $tenant->type() !== $type) {
            throw new TenantMismatchException('Panel '.$panel->id().' has '.($type === null ? 'no tenants' : 'tenants of the type "'.$type.'"')
                .', not "'.$tenant->type().'".');
        }

        return new self($container ?? app(), $panel, $tenant, $origin);
    }

    public function panel(): Panel
    {
        return $this->panel;
    }

    public function tenant(): TenantRef
    {
        return $this->tenant;
    }

    public function origin(): string
    {
        return $this->origin;
    }

    public function roles(): RoleCatalog
    {
        return new CodeRoleCatalog($this->panel, $this->container->make(SchemaBuilder::class));
    }

    public function grants(): GrantManager
    {
        return new ScopedGrantManager($this->container, $this->container->make(ChangePipeline::class), $this->panel, $this->tenant, $this->origin);
    }

    public function permissions(): PermissionManager
    {
        return new ScopedPermissionManager($this->container->make(ChangePipeline::class), $this->container->make(SchemaBuilder::class), $this->panel, $this->tenant);
    }
}
