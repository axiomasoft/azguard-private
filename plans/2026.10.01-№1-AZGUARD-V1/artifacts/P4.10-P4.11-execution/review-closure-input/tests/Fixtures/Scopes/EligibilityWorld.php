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
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EligibilityWorld
{
    public static function tables(): void
    {
        Relation::morphMap(['user' => User::class], false);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('city')->nullable();
            $table->timestamps();
        });
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('organization_id');
            $table->boolean('is_active');
            $table->string('city');
            $table->timestamps();
        });
        DB::table('users')->insert([['id' => 1, 'city' => 'west'], ['id' => 2, 'city' => 'east']]);
        DB::table('projects')->insert([
            ['id' => 1, 'organization_id' => 'A', 'is_active' => true, 'city' => 'west'],
            ['id' => 2, 'organization_id' => 'B', 'is_active' => true, 'city' => 'east'],
            ['id' => 3, 'organization_id' => 'A', 'is_active' => false, 'city' => 'west'],
            ['id' => 4, 'organization_id' => 'A', 'is_active' => true, 'city' => 'east'],
        ]);
    }

    /** @return array{Authorizer, Panel, AccessRequest} */
    public static function compile(GeneratedSource $source, ?Closure $configure = null): array
    {
        RuntimePolicy::$result = true;
        RuntimePolicy::$callback = null;
        RuntimePolicy::$calls = 0;

        return AuthorizationWorld::compile($source, function (PanelBuilder $panel) use ($configure): void {
            $panel->tenants(TenantPolicy::required(Organization::class)->requireMembership(new Membership))
                ->scopes(AssignmentScopePolicy::inherit(EligibilityProjectScope::make()->filter(ActiveProjects::class)))
                ->roles([EligibilitySellerRole::class, EligibilityAnalystRole::class]);
            $configure?->__invoke($panel);
        }, rootRole: EligibilityAdminRole::class);
    }

    public static function scope(int $id = 1, string $tenant = 'A'): AccessScope
    {
        return AccessScope::in(TenantRef::of('org', $tenant), AssignmentScopeRef::of('crm.project', $id));
    }

    public static function role(string $key, int $id = 1, array $fields = []): RoleContribution
    {
        return RoleContribution::of(RoleKey::of('admin', $key), self::scope($id), 'generated', fields: $fields);
    }

    public static function direct(int $id = 1): Grant
    {
        return Grant::of(PermissionPattern::of('admin', 'orders.view'), 'generated', self::scope($id));
    }
}
