<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\FailingSource;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Project;

it('R44 a failing source denies after a valid DB grant or admin in either source order', function (bool $reverse, bool $admin): void {
    $sources = [World::database(), new FailingSource];
    $panel = World::compile(sources: $reverse ? array_reverse($sources) : $sources);
    $user = $admin ? 3 : 1;
    World::assertDecision(World::decide($panel, user: $user), false, DecisionReason::SourceError, 'sources');
    $panel = World::compile();
    World::assertDecision(World::decide($panel, user: $user), true, $admin ? DecisionReason::SuperAdmin : DecisionReason::Granted);
})->with([false, true])->with([false, true]);

it('R42 DB and relation assignments independently support the same PHP role on reads', function (): void {
    Project::query()->findOrFail(1)->members()->attach(1, ['role' => 'seller']);
    $panel = World::compile(sources: [World::database(), RelationSource::make(ProjectScope::make(), 'members', 'pivot.role')]);
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
    World::clear();
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
    Project::query()->findOrFail(1)->members()->detach(1);
    app()->forgetScopedInstances();
    app()->forgetInstance(Authorizer::class);
    World::assertDecision(World::decide($panel), false, DecisionReason::NotGranted);
});
