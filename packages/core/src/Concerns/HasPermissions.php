<?php

declare(strict_types=1);

namespace AzGuard\Concerns;

use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Contracts\ContextGuard;
use AzGuard\Contracts\PermissionContext;
use AzGuard\Contracts\PermissionResolverInterface;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Support\PanelResolver;
use AzGuard\Support\PermissionName;
use AzGuard\Support\RequestState;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnitEnum;

trait HasPermissions
{
    /**
     * Check if the user has a permission on a panel.
     *
     * $permission may be a fully-qualified key string ("app.posts.edit") or a
     * permission enum case, which is scoped to the panel automatically.
     *
     * Optional $context allows a one-off contextual check without changing
     * global state. Use hasPermissionIn() as a more readable alternative.
     */
    public function hasPermission(string|UnitEnum $permission, string|BackedEnum|null $panelId = null, ?PermissionContext $context = null): bool
    {
        $panelId = PanelResolver::resolveDefault($panelId === null ? null : PanelResolver::normalizeId($panelId));
        $key = PermissionName::resolve($permission, $panelId);

        if ($context instanceof PermissionContext) {
            $guard = $this->contextGuard();

            // No context package installed — fall back to a global check.
            return $guard === null
                ? $this->permissionSet($panelId)->grants($key)
                : $guard->checkInContext($this, $context->contextType(), $context->contextId(), $key, $panelId);
        }

        return $this->permissionSet($panelId)->grants($key);
    }

    /**
     * Contextual permission check — does not mutate global state.
     *
     *   $user->hasPermissionIn('workspace', 42, 'app.posts.edit');
     *   $user->hasPermissionIn('workspace', 42, 'app.posts.edit', 'admin');
     */
    public function hasPermissionIn(
        string $contextType,
        int|string $contextId,
        string|UnitEnum $permission,
        string|BackedEnum|null $panelId = null,
    ): bool {
        $panelId = PanelResolver::resolveDefault($panelId === null ? null : PanelResolver::normalizeId($panelId));
        $guard = $this->contextGuard();

        if ($guard === null) {
            // Silent false is intentional (global fallback), but observable: warn
            // once per request so a missing context backend is not a silent
            // false-negative. RequestState is a scoped binding (flushed per
            // request) — Octane-safe, unlike a static flag which would warn once
            // per worker only.
            app(RequestState::class)->once(
                'context-guard-missing',
                static function (): void {
                    Log::warning(
                        'AzGuard: hasPermissionIn() called but no ContextGuard is bound; returning false. '
                        .'Install azguard/context or bind '.ContextGuard::class.' to enable contextual checks.',
                    );
                },
            );

            return false;
        }

        return $guard->checkInContext(
            $this,
            $contextType,
            $contextId,
            PermissionName::resolve($permission, $panelId),
            $panelId,
        );
    }

    /**
     * Whether the optional azguard/context ContextGuard is bound. When false,
     * hasPermissionIn() always returns false — there is no contextual backend.
     */
    public function hasContextGuard(): bool
    {
        return app()->bound(ContextGuard::class);
    }

    /**
     * Resolve the optional context package's ContextGuard, or null when the
     * azguard/context package is not installed.
     */
    private function contextGuard(): ?ContextGuard
    {
        return $this->hasContextGuard()
            ? app(ContextGuard::class)
            : null;
    }

    /**
     * Silent version: never throws. Use in Blade / UI.
     */
    public function checkPermission(string|UnitEnum $permission, string|BackedEnum|null $panelId = null, ?PermissionContext $context = null): bool
    {
        try {
            return $this->hasPermission($permission, $panelId, $context);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Get the PermissionSet for a panel.
     * All caching is delegated to EffectivePermissionResolver.
     */
    public function permissionSet(string|BackedEnum|null $panelId = null): PermissionSet
    {
        return $this->permissionResolver()->forUser(
            $this,
            PanelResolver::resolveDefault($panelId === null ? null : PanelResolver::normalizeId($panelId)),
        );
    }

    /**
     * Get all permission keys for a panel as a Collection.
     *
     * @return Collection<int, string>
     */
    public function permissions(string|BackedEnum|null $panelId = null): Collection
    {
        return collect($this->permissionSet($panelId)->keys());
    }

    /**
     * Whether this user is a super-admin on the panel — i.e. holds the global
     * wildcard ('*'), which bypasses every ability via Gate::before.
     *
     * Reuses the request-scoped permission cache (no extra queries). Wire
     * absolute-allow in a Gate::before hook off this method — see the docs.
     */
    public function isSuperAdmin(string|BackedEnum|null $panelId = null): bool
    {
        return $this->permissionSet($panelId)->isWildcard();
    }

    /**
     * Flush the permission cache for this user.
     * If $panelId is null, flushes all panels.
     * Called automatically by assignRole / removeRole / syncRoles.
     */
    public function flushPermissions(string|BackedEnum|null $panelId = null): void
    {
        $resolver = $this->permissionResolver();

        if ($panelId !== null) {
            $resolver->forgetForUser($this, PanelResolver::normalizeId($panelId));

            return;
        }

        $panels = app(AzGuardManagerInterface::class)->getPanels();

        foreach (array_keys($panels) as $id) {
            $resolver->forgetForUser($this, $id);
        }

        if (! isset($panels['app'])) {
            $resolver->forgetForUser($this, 'app');
        }
    }

    private function permissionResolver(): PermissionResolverInterface
    {
        return app(PermissionResolverInterface::class);
    }
}
