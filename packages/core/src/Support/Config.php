<?php

declare(strict_types=1);

namespace AzGuard\Support;

use AzGuard\Abilities\DefaultAbilitiesResolver;
use AzGuard\AzGuardManager;
use AzGuard\Contracts\AbilitiesResolver;
use AzGuard\Contracts\AzGuardManagerInterface;
use AzGuard\Contracts\PermissionMatcher;
use AzGuard\Contracts\PermissionResolverInterface;
use AzGuard\Contracts\RolePermissionValidator;
use AzGuard\Exceptions\InvalidMorphTypeException;
use AzGuard\Models\DirectGrant;
use AzGuard\Models\ModelHasScope;
use AzGuard\Models\Role;
use AzGuard\Models\RolePermission;
use AzGuard\Registry\Matching\WildcardPermissionMatcher;
use AzGuard\Registry\Resolver\EffectivePermissionResolver;
use AzGuard\Registry\Validation\CatalogRolePermissionValidator;

/**
 * Centralised config accessor for AzGuard.
 *
 * Replaces scattered config('az-guard.*') calls with typed static methods.
 * Inspired by spatie/laravel-permission Support\Config.
 *
 * Usage:
 *   Config::roleModel()               // 'AzGuard\Models\Role'
 *   Config::rolesTable()              // 'az_guard_roles'
 *   Config::rolePermissionsTable()    // 'az_guard_role_permissions'
 *   Config::isEnabled('teams')        // false
 */
final class Config
{
    // ─── Models ────────────────────────────────────────────────────────────

    /** @return class-string<Role> */
    public static function roleModel(): string
    {
        /** @var class-string<Role> $model */
        $model = config('az-guard.models.role', Role::class);

        return $model;
    }

    /** @return class-string<ModelHasScope> */
    public static function scopeModel(): string
    {
        /** @var class-string<ModelHasScope> $model */
        $model = config('az-guard.models.scope', ModelHasScope::class);

        return $model;
    }

    /** @return class-string<DirectGrant> */
    public static function directGrantModel(): string
    {
        /** @var class-string<DirectGrant> $model */
        $model = config('az-guard.models.direct_grant', DirectGrant::class);

        return $model;
    }

    /** @return class-string<RolePermission> */
    public static function rolePermissionModel(): string
    {
        /** @var class-string<RolePermission> $model */
        $model = config('az-guard.models.role_permission', RolePermission::class);

        return $model;
    }

    public static function modelsNamespace(): string
    {
        return (string) config('az-guard.models_namespace', 'App\\Models\\');
    }

    // ─── Tables ────────────────────────────────────────────────────────────

    public static function rolesTable(): string
    {
        return (string) config('az-guard.table_names.roles', 'roles');
    }

    public static function modelHasRolesTable(): string
    {
        return (string) config('az-guard.table_names.model_has_roles', 'model_has_roles');
    }

    public static function modelHasScopesTable(): string
    {
        return (string) config('az-guard.table_names.model_has_scopes', 'model_has_scopes');
    }

    public static function rolePermissionsTable(): string
    {
        return (string) config('az-guard.table_names.role_permissions', 'az_guard_role_permissions');
    }

    public static function directGrantsTable(): string
    {
        return (string) config('az-guard.table_names.direct_grants', 'az_direct_grants');
    }

    /** @param 'roles'|'model_has_roles'|'model_has_scopes'|'role_permissions'|'direct_grants' $key */
    public static function tableName(string $key): string
    {
        return (string) config("az-guard.table_names.{$key}");
    }

    // ─── Columns ───────────────────────────────────────────────────────────

    /**
     * Morph key type for polymorphic tables (model_has_roles, model_has_scopes,
     * az_direct_grants). Drives MorphColumns. Fails loud on an unknown value so a
     * typo cannot silently build integer columns for a ULID/UUID host.
     *
     * @return 'int'|'ulid'|'uuid'
     *
     * @throws InvalidMorphTypeException
     */
    public static function morphType(): string
    {
        return match ($value = (string) config('az-guard.column_names.morph_type', 'int')) {
            'int' => 'int',
            'ulid' => 'ulid',
            'uuid' => 'uuid',
            default => throw InvalidMorphTypeException::forValue($value, ['int', 'ulid', 'uuid']),
        };
    }

    // ─── Features ──────────────────────────────────────────────────────────

    public static function isEnabled(string $feature): bool
    {
        return (bool) config("az-guard.features.{$feature}", false);
    }

    public static function teamsEnabled(): bool
    {
        return self::isEnabled('teams');
    }

    public static function wildcardEnabled(): bool
    {
        return self::isEnabled('wildcard_permission');
    }

    public static function directGrantsEnabled(): bool
    {
        return self::isEnabled('direct_grants');
    }

    public static function auditLogEnabled(): bool
    {
        return self::isEnabled('audit_log');
    }

    public static function validateRolePermissionsEnabled(): bool
    {
        return self::isEnabled('validate_role_permissions');
    }

    // ─── Teams ────────────────────────────────────────────────────────────

    public static function teamForeignKey(): string
    {
        return (string) config('az-guard.teams.foreign_key', 'team_id');
    }

    // ─── Cache ────────────────────────────────────────────────────────────

    public static function cacheStore(): string
    {
        return (string) config('az-guard.cache.store', 'array');
    }

    /**
     * Cache TTL in seconds. Returns null for infinite cache.
     */
    public static function cacheTtl(): ?int
    {
        $value = config('az-guard.cache.expiration_time', 3600);

        return $value !== null ? (int) $value : null;
    }

    // ─── Middleware ─────────────────────────────────────────────────────────

    public static function checkAccessAlias(): string
    {
        return (string) config('az-guard.middleware.check_access_alias', 'check.access');
    }

    // ─── Panels ───────────────────────────────────────────────────────────

    /** @return array<string> */
    public static function panels(): array
    {
        return (array) config('az-guard.panels', []);
    }

    /**
     * Panel id to use when no panel is active on the current request.
     * Null means "do not guess" — authorization refuses to pick a panel.
     */
    public static function defaultPanel(): ?string
    {
        $value = config('az-guard.default_panel');

        return $value !== null ? (string) $value : null;
    }

    /**
     * Opt-in strict mode: resolving an explicit, unregistered panel throws
     * PanelNotFoundException instead of the default lenient (best-effort) resolution.
     */
    public static function strictPanelsEnabled(): bool
    {
        return (bool) config('az-guard.strict_panels', false);
    }

    // ─── Grant Sources ────────────────────────────────────────────────────

    /**
     * Explicit allowlist of GrantSource FQCNs, or null to use all tagged sources.
     *
     * @return list<class-string>|null
     */
    public static function grantSources(): ?array
    {
        $value = config('az-guard.grant_sources');

        return is_array($value) ? array_values($value) : null;
    }

    public static function failOnSourceException(): bool
    {
        return (bool) config('az-guard.fail_on_source_exception', false);
    }

    public static function pruneExpiredDaily(): bool
    {
        return (bool) config('az-guard.prune_expired_daily', false);
    }

    // ─── Extension Points ─────────────────────────────────────────────────

    /**
     * Concrete class bound to AzGuardManagerInterface (and the AzGuard facade).
     * Swappable single active-strategy seam — override to replace the manager.
     *
     * @return class-string<AzGuardManagerInterface>
     */
    public static function managerClass(): string
    {
        /** @var class-string<AzGuardManagerInterface> $class */
        $class = config('az-guard.manager', AzGuardManager::class);

        return $class;
    }

    /**
     * Concrete class bound to PermissionResolverInterface. Swappable single
     * active-strategy seam — override to replace permission resolution.
     *
     * @return class-string<PermissionResolverInterface>
     */
    public static function resolverClass(): string
    {
        /** @var class-string<PermissionResolverInterface> $class */
        $class = config('az-guard.resolver', EffectivePermissionResolver::class);

        return $class;
    }

    /**
     * Concrete class bound to PermissionMatcher — the wildcard matching grammar.
     * Swappable seam; the default keeps the historical dot-crossing behaviour.
     *
     * @return class-string<PermissionMatcher>
     */
    public static function matcherClass(): string
    {
        /** @var class-string<PermissionMatcher> $class */
        $class = config('az-guard.matcher', WildcardPermissionMatcher::class);

        return $class;
    }

    /**
     * Concrete class bound to AbilitiesResolver — the curated frontend ability
     * projection used by AzGuard::abilitiesFor(). Swappable seam.
     *
     * @return class-string<AbilitiesResolver>
     */
    public static function abilitiesResolverClass(): string
    {
        /** @var class-string<AbilitiesResolver> $class */
        $class = config('az-guard.abilities_resolver', DefaultAbilitiesResolver::class);

        return $class;
    }

    /**
     * Concrete class bound to RolePermissionValidator — the opt-in saving()
     * guard for role permission keys. Swappable seam.
     *
     * @return class-string<RolePermissionValidator>
     */
    public static function rolePermissionValidatorClass(): string
    {
        /** @var class-string<RolePermissionValidator> $class */
        $class = config('az-guard.role_permission_validator', CatalogRolePermissionValidator::class);

        return $class;
    }
}
