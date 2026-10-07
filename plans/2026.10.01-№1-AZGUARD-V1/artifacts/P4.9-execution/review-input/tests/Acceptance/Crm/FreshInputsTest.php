<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Crm\Models\User;

it('R15 V112 host city and active fields refresh under the identical grant state token', function (): void {
    $panel = World::compile();
    $before = World::decide($panel);
    World::assertDecision($before, true, DecisionReason::Granted);
    User::query()->whereKey(1)->update(['city_id' => 2]);
    $after = World::decide($panel);
    World::assertDecision($after, false, DecisionReason::NotGranted);
    expect($after->state->equals($before->state))->toBeTrue();
    User::query()->whereKey(1)->update(['city_id' => 1]);
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
    Project::query()->whereKey(1)->update(['is_active' => false]);
    $inactive = World::decide($panel);
    World::assertDecision($inactive, false, DecisionReason::AssignmentScopeIneligible);
    expect(World::database()->state($panel, World::scope()->tenant)->equals($before->state))->toBeTrue();
    Project::query()->whereKey(1)->update(['is_active' => true]);
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
});
