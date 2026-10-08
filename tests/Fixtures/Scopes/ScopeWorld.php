<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
use AzGuard\Scopes\TenantPolicy;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Panels\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class ScopeWorld
{
    /** @return array{Authorizer, Panel, AccessRequest} */
    public static function compile(GeneratedSource $source, string $mode = 'inherit', ?Closure $configure = null, ?StoreScope $definition = null, bool $tenant = true): array
    {
        RuntimePolicy::$result = true;
        RuntimePolicy::$callback = null;
        RuntimePolicy::$calls = 0;
        Relation::morphMap(['user' => User::class], false);

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->timestamps();
            });
            Model::unguarded(fn () => User::query()->create(['id' => 1]));
        }

        return AuthorizationWorld::compile($source, function (PanelBuilder $panel) use ($mode, $configure, $definition, $tenant): void {
            $policy = $mode === 'none' ? AssignmentScopePolicy::none() : AssignmentScopePolicy::{$mode}($definition ?? new StoreScope);
            $panel->scopes($policy)->tenants($tenant ? TenantPolicy::required(Organization::class)->requireMembership(new Membership) : TenantPolicy::none());

            if ($configure !== null) {
                $configure($panel);
            }
        }, rootRole: $mode === 'none' || $definition !== null ? WideReaderRole::class : ReaderRole::class);
    }

    public static function scope(string $tenant = 'A', ?int $context = null): AccessScope
    {
        return AccessScope::in(TenantRef::of('org', $tenant), $context === null ? null : AssignmentScopeRef::of('store', $context));
    }

    public static function grant(AccessScope $scope, bool $role = false): Grant|RoleContribution
    {
        return $role
            ? RoleContribution::of(RoleKey::of('admin', 'reader'), $scope, 'generated')
            : Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', $scope);
    }
}
