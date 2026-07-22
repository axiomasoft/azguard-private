<?php

declare(strict_types=1);

use AzGuard\Context\Contracts\MergeStrategy;
use AzGuard\Contracts\PermissionLayer;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Tests\Stubs\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A merge strategy an integrator might bind via config('az-guard-context.merge_strategy').
 * Ignores both inputs and always grants a single sentinel permission, so the
 * swap is observable on the real PermissionLayer container binding.
 */
class SpyMergeStrategy implements MergeStrategy
{
    public static int $mergeCalls = 0;

    public function merge(PermissionSet $global, ?PermissionSet $context): PermissionSet
    {
        self::$mergeCalls++;

        return PermissionSet::fromKeys(['spy.sentinel']);
    }
}

it('honours a config-overridden merge strategy on the PermissionLayer binding', function () {
    config()->set('az-guard-context.merge_strategy', SpyMergeStrategy::class);
    app()->forgetScopedInstances();
    SpyMergeStrategy::$mergeCalls = 0;

    $user = User::factory()->create();

    $layer = app(PermissionLayer::class);
    $result = $layer->apply(PermissionSet::fromKeys(['app.dashboard.view']), $user, 'app');

    expect(SpyMergeStrategy::$mergeCalls)->toBeGreaterThan(0)
        ->and($result->grants('spy.sentinel'))->toBeTrue()
        ->and(app(MergeStrategy::class))->toBeInstanceOf(SpyMergeStrategy::class);
});
