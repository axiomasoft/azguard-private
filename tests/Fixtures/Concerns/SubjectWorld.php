<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns;

use AzGuard\Authorization\Authorizer;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Concerns\Roles\AuditorRole;
use AzGuard\Tests\Fixtures\Concerns\Roles\EditorRole;
use AzGuard\Tests\Fixtures\Concerns\Roles\ManagerRole;
use AzGuard\Tests\Fixtures\Concerns\Roles\RootRole;
use AzGuard\Tests\Fixtures\Concerns\Roles\SupportRole;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Two panels without tenants over one storage, both taking `Member` and `CustomGuardMember`: `admin` (prefix `backoffice`, default panel,
 * roles manager/support/root/auditor) and `cabinet` (role editor/auditor). Each panel writes through its own
 * `DatabaseSource`.
 */
final class SubjectWorld
{
    public static function seed(): void
    {
        Relation::morphMap(['member' => Member::class], false);
        Carbon::setTestNow('2026-10-07T12:00:00Z');
        Member::$default = null;
        Schema::create('members', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('secret')->nullable();
            $table->boolean('is_root')->default(false);
        });
        app(StorageSchema::class)->create('default');
        Member::query()->insert([['id' => 1, 'name' => 'Анна'], ['id' => 2, 'name' => 'Борис']]);
    }

    /**
     * @param  (Closure(PanelBuilder): mixed)|null  $admin
     * @return array{PanelRegistry, CurrentPanel}
     */
    public static function compile(bool $adminIsDefault = true, ?Closure $admin = null, ?Closure $cabinet = null): array
    {
        [$resolver, $current, $registry] = PanelWorld::compile([
            AdminPanel::class => static function (PanelBuilder $panel) use ($adminIsDefault, $admin): void {
                $panel->for([Member::class, CustomGuardMember::class])->default($adminIsDefault)->resourcePrefix('backoffice')
                    ->permissions([AdminPermission::class, SharedPermission::class, DatabaseSource::make()])
                    ->roles([ManagerRole::class, SupportRole::class, RootRole::class, AuditorRole::class])
                    ->policies([PolicyBinding::for(AdminPermission::Profile, ProfilePolicy::class)]);
                $admin?->__invoke($panel);
            },
            CabinetPanel::class => static function (PanelBuilder $panel) use ($cabinet): void {
                $panel->for([Member::class, CustomGuardMember::class])->permissions([CabinetPermission::class, SharedPermission::class, DatabaseSource::make()])
                    ->roles([EditorRole::class, AuditorRole::class]);
                $cabinet?->__invoke($panel);
            },
        ]);
        app()->instance(PanelRegistry::class, $registry);
        app()->instance(CurrentPanel::class, $current);
        app()->forgetInstance(PanelResolver::class);
        app()->forgetInstance(Authorizer::class);
        app()->forgetScopedInstances();
        app()->instance(CurrentPanel::class, $current);

        return [$registry, $current];
    }

    public static function member(int $id = 1): Member
    {
        return Member::query()->findOrFail($id);
    }

    /** @return list<string> panel|role|subject|context|origin of each stored role grant */
    public static function roleRows(): array
    {
        return array_map(static fn (object $row): string => implode('|', [$row->panel, $row->role, $row->subject_id, $row->context_key, $row->origin]),
            app(StorageRegistry::class)->get('default')->table('role_grants')->orderBy('id')->get()->all());
    }

    /** @return list<string> panel|permission|subject|context|origin of each stored permission grant */
    public static function permissionRows(): array
    {
        return array_map(static fn (object $row): string => implode('|', [$row->panel, $row->permission, $row->subject_id, $row->context_key, $row->origin]),
            app(StorageRegistry::class)->get('default')->table('permission_grants')->orderBy('id')->get()->all());
    }
}
