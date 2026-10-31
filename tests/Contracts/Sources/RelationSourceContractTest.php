<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Sources;

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Authorization\Visibility;
use AzGuard\Contracts\Scopes\MapsAssignmentScope;
use AzGuard\Contracts\Scopes\ProvidesAccessScope;
use AzGuard\Contracts\Scopes\ProvidesAssignmentScope;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\ContextAware;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Testing\Contracts\SourceContractTests;
use AzGuard\Tests\Fixtures\Sources\Relation\EditorRole;
use AzGuard\Tests\Fixtures\Sources\Relation\Member;
use AzGuard\Tests\Fixtures\Sources\Relation\OwnerRole;
use AzGuard\Tests\Fixtures\Sources\Relation\Project;
use AzGuard\Tests\Fixtures\Sources\Relation\ProjectDefinition;
use AzGuard\Tests\Fixtures\Sources\Relation\RelationPermission;
use AzGuard\Tests\Fixtures\Sources\Relation\RelationWorld;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class RelationContractResource extends Model implements MapsAssignmentScope, ProvidesAccessScope, ProvidesAssignmentScope
{
    use ContextAware;

    protected $table = 'relation_contract_resources';

    public $timestamps = false;

    public function azguardContextType(): string
    {
        return 'project';
    }

    public function azguardContextRelation(): ?string
    {
        return 'project';
    }

    public function azguardScope(): AccessScope
    {
        return AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('project', (string) $this->getAttribute('project_id')));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}

final class RelationSourceContractTest extends TestCase
{
    use SourceContractTests;

    protected function setUp(): void
    {
        parent::setUp();
        RelationWorld::seed();
    }

    protected function tearDown(): void
    {
        RelationWorld::reset();
        parent::tearDown();
    }

    protected function azguardSource(): Source
    {
        return RelationSource::make(new ProjectDefinition, 'members', 'pivot.role');
    }

    protected function azguardAttach(PanelBuilder $panel, Source $source): void
    {
        $panel->for(Member::class)->plugins([ScopesPlugin::inherit(new ProjectDefinition)])->roles([EditorRole::class, OwnerRole::class])
            ->permissions([RelationPermission::class, $source]);
    }

    protected function azguardPermissions(): array
    {
        return [RelationPermission::View, RelationPermission::Edit];
    }

    protected function azguardSubject(): Model
    {
        return Member::query()->findOrFail(1);
    }

    public function test_membership_revoke_is_seen_in_the_same_request(): void
    {
        $project = RelationWorld::project();
        RelationWorld::attach($project);
        $definition = new ProjectDefinition(global: true);
        EditorRole::$definitions = [$definition];
        [$panel] = RelationWorld::compile([RelationSource::make($definition, 'members', 'pivot.role')]);
        $request = RelationWorld::request()->inScope(AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('project', 7)));
        $authorizer = app(Authorizer::class);

        $relations = array_values(array_filter($panel->attachedSources(), static fn ($source) => $source instanceof RelationSource));
        $this->assertCount(1, $relations);
        $this->assertSame(Volatility::Volatile, $relations[0]->volatility());

        $first = $authorizer->decide($panel, $request);
        $this->assertTrue($first->allowed(), $first->reason->value);
        $project->members()->detach(1);
        $this->assertFalse($authorizer->decide($panel, $request)->allowed(), 'Revoked host membership must not be cached.');
    }

    public function test_selection_isolates_tenant_subject_and_context_type(): void
    {
        RelationWorld::attach(RelationWorld::project(7, 'A'));
        RelationWorld::attach(RelationWorld::project(8, 'B'));
        RelationWorld::attach(RelationWorld::project(9, 'A'), 2);
        $source = RelationSource::make(new ProjectDefinition, 'members', 'pivot.role');
        [, $frame] = RelationWorld::compile([$source], AccessScope::in(TenantRef::of('organization', 'A')));
        $key = PermissionKey::of('admin', RelationPermission::Edit->value);
        $selected = $source->contextsCovering(SubjectRef::of('user', 1), $key, 'project', $frame);

        $this->assertSame(['7'], array_map(fn ($ref) => $ref->id(), $selected->refs()));
        $this->assertCount(1, $selected->contributions());
        $this->assertTrue($selected->contributions()[0]->scope->equals(RelationWorld::scope(7)));
        $this->assertSame([], $source->contextsCovering(SubjectRef::of('user', 1), $key, 'store', $frame)->refs());
        $this->assertSame(['9'], array_map(fn ($ref) => $ref->id(), $source->contextsCovering(SubjectRef::of('user', 2), $key, 'project', $frame)->refs()));
    }

    public function test_membership_scalar_and_batch_match_and_unmapped_visibility_is_refused(): void
    {
        RelationWorld::attach(RelationWorld::project(7));
        RelationWorld::project(8);
        $definition = new ProjectDefinition(global: true);
        EditorRole::$definitions = [$definition];
        [$panel] = RelationWorld::compile([RelationSource::make($definition, 'members', 'pivot.role')]);
        $scope = static fn (int $id) => AccessScope::in(TenantRef::global(), AssignmentScopeRef::of('project', $id));
        $requests = [RelationWorld::request()->inScope($scope(7)), RelationWorld::request()->inScope($scope(8))];
        $engine = app(Authorizer::class);
        $batch = $engine->decideMany($requests);
        $this->assertTrue($engine->decide($panel, $requests[0])->allowed());
        $this->assertFalse($engine->decide($panel, $requests[1])->allowed());
        $this->assertTrue($batch->get(0)->allowed());
        $this->assertFalse($batch->get(1)->allowed());

        $this->expectException(VisibilityNotSupportedException::class);
        app(Visibility::class)->visibleTo($panel, Project::query(), SubjectRef::of('user', 1), RelationPermission::Edit);
    }

    public function test_queryable_membership_matches_scalar_batch_and_exact_visibility(): void
    {
        Schema::create('relation_contract_resources', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
        });
        RelationWorld::attach(RelationWorld::project(7));
        RelationWorld::project(8);
        RelationContractResource::query()->insert([['id' => 1, 'project_id' => 7], ['id' => 2, 'project_id' => 8]]);
        $definition = new ProjectDefinition(global: true);
        EditorRole::$definitions = [$definition];
        [$panel] = RelationWorld::compile([RelationSource::make($definition, 'members', 'pivot.role')]);
        $resources = RelationContractResource::query()->orderBy('id')->get();
        $requests = $resources->map(static fn ($resource) => RelationWorld::request()->on(null, $resource))->all();
        $authorizer = app(Authorizer::class);
        $batch = $authorizer->decideMany($requests);
        $this->assertTrue($authorizer->decide($panel, $requests[0])->allowed());
        $this->assertFalse($authorizer->decide($panel, $requests[1])->allowed());
        $this->assertTrue($batch->get(0)->allowed());
        $this->assertFalse($batch->get(1)->allowed());
        $this->assertSame([1], app(Visibility::class)->visibleTo($panel, RelationContractResource::query(), SubjectRef::of('user', 1), RelationPermission::Edit)->pluck('id')->all());
    }
}
