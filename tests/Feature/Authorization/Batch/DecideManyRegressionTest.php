<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Exceptions\RecursionDetectedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\FencedCacheSource;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Sources\Database\DatabasePermission;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

uses()->group('batch');

it('rejects warmed direct and administrative authority exactly at batch expiry', function (bool $admin): void {
    $expires = Carbon::now('UTC')->addSecond()->toDateTimeImmutable();
    $source = new FencedCacheSource;
    $source->reuse = Volatility::Stable;
    $source->direct = $admin ? [] : [AuthorizationWorld::grant(expires: $expires)];
    $source->roles = $admin ? [RoleContribution::of(RoleKey::of('admin', 'root'), AccessScope::in(TenantRef::global()), 'generated', expiresAt: $expires)] : [];
    [$engine, , $request] = AuthorizationWorld::compile($source, fn (PanelBuilder $panel) => $panel->cache('array'));
    $requests = [$request, $request->on(null, (object) ['id' => 2])];

    foreach ($engine->decideMany($requests) as $decision) {
        expect($decision->allowed())->toBeTrue();
    }
    Carbon::setTestNow($expires);

    foreach ($engine->decideMany($requests) as $decision) {
        expect($decision->reason)->toBe(DecisionReason::NotGranted);
    }
    expect($source->revision)->toBe(1);
})->with(['direct grant' => false, 'administrative role' => true]);

it('rejects cross scalar batch recursion and releases the guard in finally', function (string $direction): void {
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource);
    $request = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy'));
    RuntimePolicy::$callback = $direction === 'batch to scalar'
        ? fn () => $engine->decide($panel, $request)->allowed()
        : fn () => $engine->decideMany([$request])->get(0)->allowed();
    Log::spy();
    $failed = $direction === 'batch to scalar' ? $engine->decideMany([$request])->get(0) : $engine->decide($panel, $request);
    expect($failed->reason)->toBe(DecisionReason::PolicyError);
    Log::shouldHaveReceived('warning')->with('AzGuard evaluation failed.', Mockery::on(fn (array $data): bool => $data['exception'] === RecursionDetectedException::class));
    RuntimePolicy::$callback = null;
    expect($engine->decide($panel, $request)->allowed())->toBeTrue()->and($engine->decideMany([$request])->get(0)->allowed())->toBeTrue();
})->with(['batch to scalar', 'scalar to batch']);

it('permits a policy to check another permission scheduled later in the same batch', function (): void {
    [$engine, $panel, $view] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]));
    $policy = AccessRequest::for($view->subject(), PermissionKey::of('admin', 'orders.policy'));
    RuntimePolicy::$callback = fn () => $engine->decide($panel, $view)->allowed();
    $scalar = [$engine->decide($panel, $policy), $engine->decide($panel, $view)];
    Log::spy();
    $batch = $engine->decideMany([$policy, $view]);
    expect($batch->get(0)->allowed())->toBeTrue()->and($batch->get(0)->reason)->toBe(DecisionReason::Policy)
        ->and($batch->get(1)->allowed())->toBeTrue()->and($batch->get(1)->reason)->toBe(DecisionReason::Granted);

    foreach ($scalar as $i => $decision) {
        $this->assertEquals($decision, $batch->get($i));
    }
    Log::shouldNotHaveReceived('warning');
});

it('runs a writing hook once and fails subject groups read at different states without evaluating again', function (): void {
    app(StorageSchema::class)->create('default');
    User::query()->insert(['id' => 2]);
    DatabaseWorld::insert('permission', [DatabaseWorld::row('permission'), DatabaseWorld::row('permission', overrides: ['subject_id' => '2'])]);
    $touches = 0;
    $observed = [];
    [$engine] = CacheWorld::database(DatabaseSource::make(), StateRefresh::Check, function (PanelBuilder $panel) use (&$touches, &$observed): void {
        $panel->before(function (EvaluationContext $context) use (&$touches): BeforeResult {
            if ($context->subjectModel()?->getKey() === 2) {
                $touches++;
                DatabaseWorld::storage()->mutate('admin', fn (StorageMutation $mutation) => $mutation->touch('admin'));
            }

            return BeforeResult::Continue;
        })->after(function (Decision $decision) use (&$observed): void {
            $observed[] = $decision;
        });
    });
    $one = DatabaseWorld::request();
    $two = AccessRequest::for(SubjectRef::of('user', 2), $one->permission());
    $set = $engine->decideMany([$one, $two, DatabaseWorld::request(DatabasePermission::Policy)]);
    expect($touches)->toBe(1)->and($set)->toHaveCount(3)->and($observed)->toHaveCount(3)
        ->and($set->get(0)->reason)->toBe(DecisionReason::ConsistencyError)->and($set->get(1)->reason)->toBe(DecisionReason::ConsistencyError)
        ->and($set->get(2)->allowed())->toBeTrue()->and($set->get(2)->reason)->toBe(DecisionReason::Policy);

    foreach ($set as $i => $decision) {
        expect($decision->state)->toBeInstanceOf(CodeStateToken::class);
        $this->assertEquals($decision, $observed[$i]);
    }
    expect($set->states())->toHaveCount(1);
});
