<?php

declare(strict_types=1);

namespace AzGuard\Tests\Contracts\Sources;

use AzGuard\Contracts\Sources\Source;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\AssignmentScopePolicy;
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
        $panel->for(Member::class)->scopes(AssignmentScopePolicy::inherit(new ProjectDefinition))->roles([EditorRole::class, OwnerRole::class])
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
}
