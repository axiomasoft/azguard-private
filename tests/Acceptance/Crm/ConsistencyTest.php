<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\StateRefresh;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use Illuminate\Support\Carbon;

it('R51 denies ordinary and scoped administrative grants exactly at expiresAt in warmed CRM memo', function (int $user): void {
    $expires = Carbon::now('UTC')->addSecond();
    World::storage()->mutate('crm', static function (StorageMutation $m) use ($user, $expires): void {
        $m->table('role_grants')->where('subject_id', (string) $user)->update(['expires_at' => $expires->format('Y-m-d H:i:s')]);
        $m->touch('crm');
    });
    $panel = World::compile(fn (PanelBuilder $p) => $p->cache('array', 60)->consistency(refresh: StateRefresh::Request));
    expect(World::decide($panel, user: $user)->allowed())->toBeTrue();
    Carbon::setTestNow($expires);
    expect(World::decide($panel, user: $user)->reason)->toBe(DecisionReason::NotGranted);
})->with([1, 3]);

it('R52 invalidates request memo on a local root revoke for both refresh modes', function (StateRefresh $refresh): void {
    $panel = World::compile(fn (PanelBuilder $p) => $p->cache('array', 60)->consistency(refresh: $refresh));
    $before = World::decide($panel);
    expect($before->allowed())->toBeTrue();
    // Separate connection isn't meaningful for in-memory CRM; real two-process evidence is Engines/RevocationRaceTest.
    World::clear();
    expect(World::decide($panel)->reason)->toBe(DecisionReason::NotGranted);
    app()->forgetScopedInstances();
    expect(World::decide($panel)->reason)->toBe(DecisionReason::NotGranted);
})->with([StateRefresh::Request, StateRefresh::Check]);
