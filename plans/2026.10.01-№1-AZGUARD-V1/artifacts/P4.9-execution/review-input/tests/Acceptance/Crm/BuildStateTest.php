<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;

it('R57 joint root sees host membership changes and rollback preserves committed authority', function (bool $rollback): void {
    $panel = World::compile();
    $engine = app(Authorizer::class);
    $storage = World::storage();
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
    $version = $storage->state('crm')->version;

    try {
        $engine->withinAuthorityTransaction($panel, function () use ($panel, $storage, $rollback): void {
            expect($storage->authorityTransaction('crm'))->not->toBeNull();
            $storage->connection()->table('organization_user')->where('organization_id', 1)->where('user_id', 1)->delete();
            World::assertDecision(World::decide($panel), false, DecisionReason::Restricted);

            if ($rollback) {
                throw new RuntimeException('host rollback');
            }
        });
    } catch (RuntimeException $e) {
        expect($rollback)->toBeTrue();
    }
    expect(World::decide($panel)->allowed())->toBe($rollback)
        ->and($storage->state('crm')->version)->toBe($version)
        ->and($storage->authorityTransaction())->toBeNull();
})->with([false, true]);

it('R57 restore and generation changes cannot revive an older warm CRM authority', function (): void {
    $panel = World::compile(fn (PanelBuilder $p) => $p->cache('array', generation: 1));
    $before = World::decide($panel);
    expect($before->allowed())->toBeTrue();
    $storage = World::storage();
    // Local restore/reset fixture uses schema lifecycle and generates a new storage incarnation.
    app(StorageSchema::class)->drop('default');
    app(StorageSchema::class)->create('default');
    $storage->mutate('crm', static fn (): null => null);
    $panel = World::compile(fn (PanelBuilder $p) => $p->cache('array', generation: 2));
    $after = World::decide($panel);
    expect($after->reason)->toBe(DecisionReason::NotGranted)
        ->and($after->state->incarnation)->not->toBe($before->state->incarnation)
        ->and($after->state->version)->toBeLessThan($before->state->version)
        ->and($after->state->generation)->toBe(2)
        ->and($after->state->fingerprint)->not->toBe($before->state->fingerprint);
});
