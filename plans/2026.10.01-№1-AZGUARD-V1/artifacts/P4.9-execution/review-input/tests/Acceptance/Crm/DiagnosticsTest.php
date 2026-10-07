<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;

it('R49 local declared grant fields expose no undeclared assignment secret in a traced decision', function (): void {
    World::clear();
    World::assign('clients.view', 1, 1, kind: 'permission', fields: ['region' => 'R1', 'eligible' => true, 'secret' => 'PRIVATE_CRM_TOKEN']);
    $panel = World::compile();
    $decision = World::decide($panel);
    World::assertDecision($decision, true, DecisionReason::Granted);
    expect($decision->grants)->toHaveCount(1)->and($decision->grants[0]->fields())->toBe(['region' => 'R1', 'eligible' => true])
        ->and(json_encode($decision, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE_CRM_TOKEN');
    World::assertDecision(World::decide($panel, 3), false, DecisionReason::NotGranted);
});
