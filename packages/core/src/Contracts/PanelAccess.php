<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use AzGuard\Authorization\Visibility;
use AzGuard\Concerns\SubjectAccess;
use AzGuard\Contracts\Catalog\PermissionCatalog;
use AzGuard\Contracts\Changes\GrantManager;
use AzGuard\Contracts\Changes\PermissionManager;
use AzGuard\Contracts\Roles\RoleCatalog;
use AzGuard\Directories\PanelDirectories;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\DecisionSetTooLargeException;
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
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Schema\PanelSchema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * One panel as a whole, for code that works with the panel and not with one subject: the subject wrapper, the
 * managers of roles, grants and permissions, the schema, decisions and the state of the stored authority.
 *
 * The panel, the tenant and the origin are fixed when the access is made. `inTenant()` and `fromOrigin()` return a new
 * access and leave this one as it is, and no method takes a panel, a tenant or an origin, so the managers it hands out
 * can neither see nor change the data of another partition. The origin selects the partition of changes and
 * stored-grant lists only; decisions read every origin. Without `inTenant()` the tenant is the current tenant of the
 * panel; a panel with tenants and neither is a `TenantRequiredException`, never an aggregate over tenants.
 *
 * @api
 */
interface PanelAccess
{
    public function definition(): Panel;

    /**
     * The same panel and origin in another tenant; this access keeps its own.
     *
     * @throws TenantMismatchException when the tenant is not of the tenant type of the panel
     */
    public function inTenant(Model|TenantRef $tenant): self;

    /**
     * The same panel and tenant with changes and stored-grant lists in another origin.
     */
    public function fromOrigin(string $origin): self;

    /**
     * The tenant of the access with the tenant-wide context.
     *
     * @throws TenantRequiredException
     */
    public function scope(): AccessScope;

    /**
     * @throws SubjectNotAcceptedException when the panel does not accept the subject
     */
    public function for(Model|Authenticatable|SubjectRef $subject): SubjectAccess;

    /** The roles defined in code; assignments are the business of the grant manager and the subject wrapper. */
    public function roles(): RoleCatalog;

    /** @throws TenantRequiredException */
    public function grants(): GrantManager;

    /** @throws TenantRequiredException */
    public function permissions(): PermissionManager;

    /** @throws TenantRequiredException */
    public function schema(): PanelSchema;

    /**
     * @throws ConflictingPanelException when the request is for another panel
     * @throws TenantMismatchException when the request names a tenant other than the one of the access
     */
    public function decide(AccessRequest $request): Decision;

    /**
     * @param  iterable<AccessRequest>  $requests
     *
     * @throws ConflictingPanelException when a request is for another panel
     * @throws TenantMismatchException when a request names a tenant other than the one of the access
     * @throws DecisionSetTooLargeException when the requests name more distinct subjects than decision_sets.max_subjects
     */
    public function decideMany(iterable $requests): DecisionSet;

    /**
     * @throws ConflictingPanelException when the request is for another panel
     * @throws TenantMismatchException when the request names a tenant other than the one of the access
     */
    public function explain(AccessRequest $request): Explanation;

    public function catalog(): PermissionCatalog;

    public function visibility(): Visibility;

    /**
     * The directories an interface searches for subjects, tenants and assignment scopes of the panel.
     */
    public function directories(): PanelDirectories;

    /**
     * The state of the stored authority of the tenant, for explicit storage management; a decision never needs it.
     *
     * @throws PanelNotWritableException when the writer of the panel keeps no state
     * @throws TenantRequiredException
     */
    public function state(): StateToken;

    /**
     * A new version of the panel state by hand, when the data of automatic roles changed.
     *
     * @throws PanelNotWritableException when the panel stores nothing
     */
    public function touch(): StateToken;
}
