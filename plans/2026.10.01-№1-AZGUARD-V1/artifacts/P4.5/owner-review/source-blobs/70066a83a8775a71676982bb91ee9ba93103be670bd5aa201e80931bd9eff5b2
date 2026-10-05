<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Relation;

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class RelationWorld
{
    public static function seed(): void
    {
        Relation::morphMap(['user' => Member::class, 'vendor' => Vendor::class, 'work_project' => Project::class], false);
        EditorRole::$definitions = [new ProjectDefinition];
        Schema::create('relation_members', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('lead_project_id')->nullable();
            $table->string('role')->nullable();
        });
        Schema::create('relation_vendors', static function (Blueprint $table): void {
            $table->id();
        });
        foreach (['relation_projects', 'relation_teams', 'relation_stores'] as $name) {
            Schema::create($name, static function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_id');
                $table->boolean('enabled')->default(true);
                $table->unsignedBigInteger('owner_id')->nullable();
            });
        }
        Schema::create('relation_project_user', static function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('member_id');
            $table->string('role')->nullable();
            $table->boolean('is_lead')->default(false);
        });
        Schema::create('relation_memberships', static function (Blueprint $table): void {
            $table->unsignedBigInteger('memberable_id');
            $table->string('memberable_type');
            $table->unsignedBigInteger('member_id');
            $table->string('role')->nullable();
            $table->boolean('is_lead')->default(false);
        });
        Member::query()->create(['id' => 1]);
        Member::query()->create(['id' => 2]);
        Vendor::query()->insert(['id' => 1]);
    }

    public static function reset(): void
    {
        Relation::morphMap([], false);
        EditorRole::$definitions = [];
    }

    /**
     * @param  list<Source>  $sources
     * @return array{Panel, EvaluationFrame, PanelRegistry}
     */
    public static function compile(array $sources, ?AccessScope $scope = null): array
    {
        [,, $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->for([Member::class, Vendor::class])->resourcePrefix(false)->permissions([RelationPermission::class, ...$sources])
            ->roles([EditorRole::class, OwnerRole::class])]);
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetInstance(Authorizer::class);
        $panel = $registry->get('admin');

        return [$panel, new EvaluationFrame(
            selectedPanel: $panel,
            selectedScope: $scope ?? self::scope(7),
            token: CodeStateToken::of('admin', $registry->buildId(), $registry->fingerprint('admin')),
            decisionNow: new DateTimeImmutable('2040-01-01T12:00:00+00:00'),
            selectedActor: ActorRef::of('user', '1'),
        ), $registry];
    }

    public static function scope(int|string $id, string $tenant = 'A', string $type = 'project'): AccessScope
    {
        return AccessScope::in(TenantRef::of('organization', $tenant), AssignmentScopeRef::of($type, $id));
    }

    public static function request(): AccessRequest
    {
        return AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', RelationPermission::Edit->value));
    }

    /** @param array<string, mixed> $attributes */
    public static function project(int $id = 7, string $tenant = 'A', array $attributes = []): Project
    {
        return Project::query()->create(array_replace(['id' => $id, 'tenant_id' => $tenant], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    public static function attach(Project $project, int $member = 1, ?string $role = 'editor', array $attributes = []): void
    {
        $project->members()->attach($member, array_replace(['role' => $role], $attributes));
    }

    /**
     * @template T
     *
     * @param  iterable<T>  $items
     * @return list<T>
     */
    public static function items(iterable $items): array
    {
        return is_array($items) ? array_values($items) : array_values(iterator_to_array($items));
    }
}
