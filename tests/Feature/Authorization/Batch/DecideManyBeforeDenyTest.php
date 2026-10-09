<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\StateToken;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Support\Facades\DB;

uses()->group('batch');

it('keeps a static before denial outside neighbouring DB authority and scalar state unchanged', function (bool $broken): void {
    app(StorageSchema::class)->create('default');
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission')]);
    $source = DatabaseSource::make();
    $failedReads = 0;

    if ($broken) {
        config(['database.connections.before_deny_broken' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::connection('before_deny_broken')->beforeExecuting(function () use (&$failedReads): void {
            $failedReads++;

            throw new RuntimeException('The DB is unavailable for the neighbouring grants check.');
        });
        $source = $source->storage(app(StorageRegistry::class)->own('before_deny_broken', 'broken_'));
    }
    [$engine, $panel] = CacheWorld::database($source, configure: fn (PanelBuilder $panel) => $panel->before(
        fn (EvaluationContext $context): BeforeResult => $context->resource()?->deny === true ? BeforeResult::Deny : BeforeResult::Continue,
    ));
    $request = DatabaseWorld::request();
    $denied = $request->on(null, (object) ['deny' => true]);
    $scalar = $engine->decide($panel, $denied);
    expect($scalar->reason)->toBe(DecisionReason::Hook)->and($scalar->state)->toBeInstanceOf(CodeStateToken::class);
    $budget = CacheWorld::emptyBudget();
    CacheWorld::listen($budget);
    $allDenied = $engine->decideMany([$denied, $denied]);
    expect($budget['grants'])->toBe(0)->and($budget['state'])->toBe(0)->and($failedReads)->toBe(0);

    foreach ($allDenied as $decision) {
        $this->assertEquals($scalar, $decision);
    }
    $set = $engine->decideMany([$denied, $request, $denied]);
    $this->assertEquals($scalar, $set->get(0));
    $this->assertEquals($scalar, $set->get(2));
    expect($set->get(1)->reason)->toBe($broken ? DecisionReason::SourceError : DecisionReason::Granted)
        ->and($set->get(1)->allowed())->toBe(! $broken);

    if ($broken) {
        expect($failedReads)->toBeGreaterThan(0);
    } else {
        expect($set->get(1)->state)->toBeInstanceOf(StateToken::class)->and($budget['grants'])->toBe(2)->and($budget['state'])->toBe(1);
    }
})->with(['healthy authority' => false, 'failing authority' => true]);
