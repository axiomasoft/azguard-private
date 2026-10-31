<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Sources;

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Testing\Contracts\SourceContractTests;
use AzGuard\Tests\Fixtures\Sources\Relation\EditorRole;
use AzGuard\Tests\Fixtures\Sources\Relation\Member;
use AzGuard\Tests\Fixtures\Sources\Relation\OwnerRole;
use AzGuard\Tests\Fixtures\Sources\Relation\ProjectDefinition;
use AzGuard\Tests\Fixtures\Sources\Relation\RelationPermission;
use AzGuard\Tests\Fixtures\Sources\Relation\RelationWorld;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

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

        $first = $authorizer->decide($panel, $request);
        $this->assertTrue($first->allowed(), $first->reason->value);
        $project->members()->detach(1);
        $this->assertFalse($authorizer->decide($panel, $request)->allowed(), 'Revoked host membership must not be cached.');
    }
}
