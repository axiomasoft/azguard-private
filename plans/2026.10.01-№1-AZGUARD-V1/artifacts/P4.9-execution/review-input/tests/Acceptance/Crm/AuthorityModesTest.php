<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission as Action;
use Illuminate\Support\Facades\DB;

it('R61 PolicyOnly never reads an assignment connection that aborts every SQL operation', function (): void {
    config(['database.connections.crm_broken' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    $connection = DB::connection('crm_broken');
    $reads = 0;
    $connection->beforeExecuting(function () use (&$reads): void {
        $reads++;

        throw new RuntimeException('assignment connection is down');
    });
    $storage = app(StorageRegistry::class)->own('crm_broken', 'broken_');
    $panel = World::compile(sources: [DatabaseSource::make()->storage($storage)]);
    $decision = World::decide($panel, action: Action::ViewOwnProfile);
    World::assertDecision($decision, true, DecisionReason::Policy);
    expect($decision->state)->toBeInstanceOf(CodeStateToken::class)->and($reads)->toBe(0);
    World::assertDecision(World::decide($panel, 6, Action::ViewOwnProfile), false, DecisionReason::Policy);
    expect($reads)->toBe(0);
    World::assertDecision(World::decide($panel), false, DecisionReason::SourceError);
    expect($reads)->toBeGreaterThan(0);
});

it('R62 policy true and before Continue never replace an assignment and admin cannot bypass policy veto', function (): void {
    World::clear();
    $panel = World::compile(fn (PanelBuilder $p) => $p->before(fn () => BeforeResult::Continue));
    World::assertDecision(World::decide($panel), false, DecisionReason::NotGranted);
    World::assign('clients.view', 1, 1, kind: 'permission');
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
    World::assertDecision(World::decide($panel, 2, Action::Update, 3), false, DecisionReason::Policy);
    World::assertDecision(World::decide($panel, 1, Action::Update, 3), true, DecisionReason::SuperAdmin);
});

it('R65 enum assignments work without dynamic definitions or a copied permission row', function (): void {
    World::clear();
    $panel = World::compile();
    World::assertDecision(World::decide($panel), false, DecisionReason::NotGranted);
    World::assign(Action::View->value, 1, 1, kind: 'permission');
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
    expect(World::storage()->table('permissions')->count())->toBe(0);
    World::assertDecision(World::decide($panel, 3), false, DecisionReason::NotGranted);
});

it('R63 read slice wildcard and superadmin cannot replace PolicyOnly owner policy', function (): void {
    World::assign('clients.*', 1, 5, kind: 'permission');
    World::assign('tenant-admin', 1, 5);
    $panel = World::compile();
    World::assertDecision(World::decide($panel, action: Action::ViewOwnProfile), true, DecisionReason::Policy);
    World::assertDecision(World::decide($panel, 6, Action::ViewOwnProfile), false, DecisionReason::Policy);
    World::assertDecision(World::decide($panel, 6), true, DecisionReason::SuperAdmin);
});

it('R66 removed role key contributes no authority until a registered PHP role is assigned', function (): void {
    World::clear();
    World::assign('removed-seller', 1, 1);
    $panel = World::compile();
    World::assertDecision(World::decide($panel), false, DecisionReason::NotGranted);
    World::assign('seller', 1, 1);
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
});
