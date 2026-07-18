<?php

declare(strict_types=1);

namespace AzGuard;

use AzGuard\Contracts\AbilitiesResolver;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Contracts\ContextGuard;
use AzGuard\Contracts\PermissionResolverInterface;
use AzGuard\Grants\GrantBuilder;
use AzGuard\Models\DirectGrant;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelProvider;
use AzGuard\Panels\PanelResolver;
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Contracts\PermissionCatalogBuilder;
use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Override;
use RuntimeException;
use UnitEnum;

final class AzGuardManager implements AzGuardManagerInterface
{
    /**
     * Container tag collected by EffectivePermissionResolver. Bind and tag a
     * custom GrantSource with this to plug it into the resolution chain.
     */
    public const string GRANT_SOURCES_TAG = 'azguard.grant_sources';

    /**
     * Container tag collected by CompositePermissionCatalog. Bind and tag a
     * custom PermissionCatalogBuilder with this to contribute definitions to
     * the catalog. Symmetric with {@see GRANT_SOURCES_TAG}.
     */
    public const string CATALOG_BUILDERS_TAG = 'azguard.catalog_builders';

    /** @var array<string, Panel> */
    protected array $panels = [];

    protected ?Panel $currentPanel = null;

    // ─── Panels ──────────────────────────────────────────────────────────────

    #[Override]
    public function registerPanel(Panel|callable $panel): void
    {
        $panelInstance = $panel instanceof Panel ? $panel : $panel();
        $this->panels[$panelInstance->getId()] = $panelInstance;
    }

    /**
     * @return array<string, Panel>
     */
    #[Override]
    public function getPanels(): array
    {
        return $this->panels;
    }

    #[Override]
    public function panel(string|BackedEnum $id): ?Panel
    {
        return $this->panels[PanelResolver::normalizeId($id)] ?? null;
    }

    #[Override]
    public function currentPanel(): ?Panel
    {
        return $this->currentPanel;
    }

    #[Override]
    public function setCurrentPanel(?Panel $panel): void
    {
        $this->currentPanel = $panel;
    }

    #[Override]
    public function permission(string|BackedEnum $panelId, string|UnitEnum $permission): string
    {
        $panel = $this->panel(id: $panelId);

        if (! $panel instanceof Panel) {
            $id = PanelResolver::normalizeId($panelId);

            throw new RuntimeException("AzGuard panel [{$id}] is not registered.");
        }

        return $panel->resolvePermission(permission: $permission);
    }

    #[Override]
    public function tryPermission(string|BackedEnum $panelId, string|UnitEnum $permission): ?string
    {
        $panel = $this->panel(id: $panelId);

        return $panel?->resolvePermission(permission: $permission);
    }

    #[Override]
    public function panelIdForPermission(UnitEnum $permission): ?string
    {
        foreach ($this->panels as $id => $panel) {
            if (in_array($permission::class, $panel->getPermissionEnums(), strict: true)) {
                return $id;
            }
        }

        return null;
    }

    // ─── Actor ─────────────────────────────────────────────────────────────────

    #[Override]
    public function isSuperAdmin(Authenticatable $user, string|BackedEnum|null $panelId = null): bool
    {
        $resolvedPanelId = PanelResolver::resolveDefault(
            $panelId === null ? null : PanelResolver::normalizeId($panelId),
        );

        return app(PermissionResolverInterface::class)->forUser($user, $resolvedPanelId)->isWildcard();
    }

    #[Override]
    public function hasContextGuard(): bool
    {
        return app()->bound(ContextGuard::class);
    }

    /**
     * Curated ability projection for the frontend: resolves ONLY the requested
     * $keys to a map of ability => bool for shared props (nav/shell). The full
     * catalog is never dumped — the allowlist is mandatory. Delegates to the
     * swappable {@see AbilitiesResolver} (config `az-guard.abilities_resolver`).
     *
     * @param  list<string>  $keys
     * @return array<string, bool>
     */
    #[Override]
    public function abilitiesFor(Authenticatable $user, string|BackedEnum|null $panelId, array $keys): array
    {
        $resolvedPanelId = PanelResolver::resolveDefault(
            $panelId === null ? null : PanelResolver::normalizeId($panelId),
        );

        return app(AbilitiesResolver::class)->forUser($user, $resolvedPanelId, $keys);
    }

    // ─── Extensions ───────────────────────────────────────────────────────────

    /**
     * Register a custom GrantSource. Bind it (singleton/scoped) if it is not
     * already bound, then tag it so EffectivePermissionResolver picks it up.
     *
     * Call this from a service provider's register() method:
     *   AzGuard::registerGrantSource(MyGrantSource::class);
     *
     * @param  class-string<GrantSource>  $sourceClass
     */
    #[Override]
    public function registerGrantSource(string $sourceClass): void
    {
        if (! app()->bound($sourceClass)) {
            app()->scoped($sourceClass);
        }

        app()->tag([$sourceClass], self::GRANT_SOURCES_TAG);
    }

    /**
     * Register a custom PermissionCatalogBuilder. Bind it (singleton) if it is
     * not already bound, then tag it so CompositePermissionCatalog picks it up.
     * The public, symmetric counterpart of {@see registerGrantSource()} — the
     * panel-scoped {@see PanelProvider::registerCustomCatalogBuilders()} remains
     * available for per-panel builders.
     *
     * Call this from a service provider's register()/boot() method:
     *   AzGuard::registerCatalogBuilder(MyCatalogBuilder::class);
     *
     * @param  class-string<PermissionCatalogBuilder>  $builderClass
     */
    #[Override]
    public function registerCatalogBuilder(string $builderClass): void
    {
        if (! app()->bound($builderClass)) {
            app()->singleton($builderClass);
        }

        app()->tag([$builderClass], self::CATALOG_BUILDERS_TAG);
    }

    // ─── Grants API ─────────────────────────────────────────────────────────

    /**
     * Return a fluent GrantBuilder for a user.
     *
     * Example:
     *   AzGuard::forUser($user)->on('app')->ttl(3600)->grant('app.x.view');
     */
    #[Override]
    public function forUser(Authenticatable $user): GrantBuilder
    {
        return new GrantBuilder(user: $user);
    }

    /**
     * Shorthand: issue a direct grant.
     *
     * @param  int|null  $ttl  TTL in seconds. null = permanent.
     *
     * @internal Positional twin of the fluent root kept for internal
     *           orchestration — the public path is forUser()->on()->grant().
     */
    #[Override]
    public function grant(
        Authenticatable $user,
        string|UnitEnum $permissionKey,
        string|BackedEnum|null $panelId = null,
        ?int $ttl = null,
    ): DirectGrant {
        $resolvedPanelId = PanelResolver::resolveDefault(
            $panelId === null ? null : PanelResolver::normalizeId($panelId),
        );

        return $this->forUser($user)->on($resolvedPanelId)->ttl($ttl)->grant($permissionKey);
    }

    /**
     * Shorthand: revoke a direct grant.
     *
     * @return int Number of deleted records.
     *
     * @internal Positional twin of the fluent root kept for internal
     *           orchestration — the public path is forUser()->on()->revoke().
     */
    #[Override]
    public function revoke(
        Authenticatable $user,
        string|UnitEnum $permissionKey,
        string|BackedEnum|null $panelId = null,
    ): int {
        $resolvedPanelId = PanelResolver::resolveDefault(
            $panelId === null ? null : PanelResolver::normalizeId($panelId),
        );

        return $this->forUser($user)->on($resolvedPanelId)->revoke($permissionKey);
    }

    /**
     * Shorthand: list active direct grants for a user in a panel.
     *
     * @return Collection<int, DirectGrant>
     *
     * @internal Positional twin of the fluent root kept for internal
     *           orchestration — the public path is forUser()->on()->grants().
     */
    #[Override]
    public function grants(
        Authenticatable $user,
        string|BackedEnum|null $panelId = null,
    ): Collection {
        $resolvedPanelId = PanelResolver::resolveDefault(
            $panelId === null ? null : PanelResolver::normalizeId($panelId),
        );

        return $this->forUser($user)->on($resolvedPanelId)->grants();
    }
}
