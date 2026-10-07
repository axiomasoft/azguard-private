<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Authorization\BatchCrmWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission as Action;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Scopes\ProjectScope;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

it('R68 preserves mixed policy DB and relation order state identities and one captured now', function (): void {
    Project::query()->findOrFail(1)->members()->attach(1, ['role' => 'seller']);
    World::assign('seller', 1, 1, panel: 'backoffice');
    $times = [];
    $configure = function (PanelBuilder $panel) use (&$times): void {
        $panel->before(function (EvaluationContext $context) use (&$times): BeforeResult {
            $times[] = $context->now()->format('c');
            Carbon::setTestNow(Carbon::now('UTC')->addDay());

            return BeforeResult::Continue;
        });
    };
    World::compile($configure, [RelationSource::make(ProjectScope::make(), 'members', 'pivot.role')], $configure);
    $one = Client::query()->findOrFail(1);
    $six = Client::query()->findOrFail(6);
    $requests = [
        BatchCrmWorld::request($six, Action::ViewOwnProfile),
        BatchCrmWorld::request($one, panel: 'backoffice'),
        BatchCrmWorld::request($one),
        BatchCrmWorld::request($one, Action::ViewOwnProfile, 'backoffice'),
        BatchCrmWorld::request(Client::query()->findOrFail(3)),
    ];
    $captured = Carbon::now('UTC')->format('c');
    $set = app(Authorizer::class)->decideMany($requests);
    expect(array_map(fn ($d) => $d->allowed(), iterator_to_array($set)))->toBe([false, true, true, true, false])
        ->and(array_map(fn ($d) => $d->reason, iterator_to_array($set)))->toBe([
            DecisionReason::Policy, DecisionReason::Granted, DecisionReason::Granted, DecisionReason::Policy, DecisionReason::NotGranted,
        ])->and($times)->toBe(array_fill(0, 5, $captured));
    $registry = app(PanelRegistry::class);
    $states = $set->states();
    $crmCode = IdentityCodec::compose(['code', 'crm', $registry->buildId()]);
    $backCode = IdentityCodec::compose(['code', 'backoffice', $registry->buildId()]);
    $backDb = IdentityCodec::compose(['storage', World::storage()->id(), 'backoffice']);
    expect(array_keys($states))->toBe([$crmCode, $backDb, $backCode])
        ->and($states[$crmCode])->toBeInstanceOf(CodeStateToken::class)->and($states[$backCode])->toBeInstanceOf(CodeStateToken::class)
        ->and($states[$backDb])->toBeInstanceOf(StateToken::class);
});

it('R68 keeps policy requests independent when a neighbouring Grants connection fails', function (): void {
    config(['database.connections.batch_broken' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    $reads = 0;
    DB::connection('batch_broken')->beforeExecuting(function () use (&$reads): void {
        $reads++;

        throw new RuntimeException('batch assignment connection unavailable');
    });
    $storage = app(StorageRegistry::class)->own('batch_broken', 'broken_');
    World::compile(sources: [DatabaseSource::make()->storage($storage)]);
    $one = Client::query()->findOrFail(1);
    $set = app(Authorizer::class)->decideMany([
        BatchCrmWorld::request($one, Action::ViewOwnProfile),
        BatchCrmWorld::request($one),
        BatchCrmWorld::request($one, Action::ViewOwnProfile),
    ]);
    expect($set->get(0)->allowed())->toBeTrue()->and($set->get(1)->reason)->toBe(DecisionReason::SourceError)
        ->and($set->get(2)->allowed())->toBeTrue()->and($set->get(0)->state)->toBeInstanceOf(CodeStateToken::class)
        ->and($reads)->toBeGreaterThan(0);
});
