<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use AzGuard\Grants\GrantBuilder;
use AzGuard\Models\DirectGrant;
use AzGuard\Panels\Panel;
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Contracts\PermissionCatalogBuilder;
use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use UnitEnum;

/**
 * Contract for AzGuardManager.
 * Type-hint this interface for testability (mock/swap the manager).
 *
 * @api
 */
interface AzGuardManagerInterface
{
    // ─── Panels ───────────────────────────────────────────────────────────────

    /**
     * Register a panel. Accepts a Panel directly or a callable returning one.
     */
    public function registerPanel(Panel|callable $panel): void;

    /**
     * Return all registered panels.
     *
     * @return array<string, Panel>
     */
    public function getPanels(): array;

    /**
     * Return a panel by id (string or backed enum), or null.
     */
    public function panel(string|BackedEnum $id): ?Panel;

    /**
     * Return the current active panel.
     */
    public function currentPanel(): ?Panel;

    /**
     * Set the current active panel.
     */
    public function setCurrentPanel(?Panel $panel): void;

    /**
     * Resolve the fully-qualified permission key for a panel.
     *
     * @throws RuntimeException when the panel is not registered
     */
    public function permission(string|BackedEnum $panelId, string|UnitEnum $permission): string;

    /**
     * Soft-resolve: returns null when the panel is not registered.
     * Safe in Blade / UI without try-catch.
     */
    public function tryPermission(string|BackedEnum $panelId, string|UnitEnum $permission): ?string;

    /**
     * Find the id of the panel that owns a permission enum (i.e. lists the enum
     * class in its permission enums), or null when no registered panel owns it.
     */
    public function panelIdForPermission(UnitEnum $permission): ?string;

    // ─── Actor ───────────────────────────────────────────────────────────────

    /**
     * Whether the user is a super-admin on the panel — i.e. holds the global
     * wildcard, bypassing every ability via Gate::before.
     */
    public function isSuperAdmin(Authenticatable $user, string|BackedEnum|null $panelId = null): bool;

    /**
     * Curated ability projection for the frontend: resolves ONLY the requested
     * $keys to a map of ability => bool. The full catalog is never dumped.
     *
     * @param  list<string>  $keys
     * @return array<string, bool>
     */
    public function abilitiesFor(Authenticatable $user, string|BackedEnum|null $panelId, array $keys): array;

    /**
     * Whether the optional azguard/context ContextGuard is bound. When false,
     * contextual checks (hasPermissionIn) always return false.
     */
    public function hasContextGuard(): bool;

    // ─── Extensions ─────────────────────────────────────────────────────────────

    /**
     * Register a custom GrantSource (bind if needed and tag it) so
     * EffectivePermissionResolver picks it up in the resolution chain.
     *
     * @param  class-string<GrantSource>  $sourceClass
     */
    public function registerGrantSource(string $sourceClass): void;

    /**
     * Register a custom PermissionCatalogBuilder (bind if needed and tag it) so
     * CompositePermissionCatalog contributes its definitions to the catalog.
     * Symmetric with {@see registerGrantSource()}.
     *
     * @param  class-string<PermissionCatalogBuilder>  $builderClass
     */
    public function registerCatalogBuilder(string $builderClass): void;

    // ─── Grants API ───────────────────────────────────────────────────────────

    /**
     * Return a fluent GrantBuilder for a user.
     *
     * AzGuard::forUser($user)->on('app')->ttl(3600)->grant('app.x');
     */
    public function forUser(Authenticatable $user): GrantBuilder;

    /**
     * Shorthand: issue a direct grant.
     *
     * @param  int|null  $ttl  TTL in seconds. null = permanent.
     */
    public function grant(
        Authenticatable $user,
        string|UnitEnum $permissionKey,
        string|BackedEnum|null $panelId = null,
        ?int $ttl = null,
    ): DirectGrant;

    /**
     * Shorthand: revoke a direct grant.
     *
     * @return int Number of deleted records.
     */
    public function revoke(
        Authenticatable $user,
        string|UnitEnum $permissionKey,
        string|BackedEnum|null $panelId = null,
    ): int;

    /**
     * Shorthand: list a user's active direct grants in a panel.
     *
     * @return Collection<int, DirectGrant>
     */
    public function grants(
        Authenticatable $user,
        string|BackedEnum|null $panelId = null,
    ): Collection;
}
