<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;

it('calls hooks positionally, not by parameter name', function (): void {
    $seen = [];
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), function (PanelBuilder $panel) use (&$seen): void {
        $panel->before(static function (AccessRequest $first, EvaluationContext $second) use (&$seen): BeforeResult {
            $seen['before'] = [$first, $second];

            return BeforeResult::Continue;
        })->after(static function (AccessRequest $first, EvaluationContext $second, Decision $third) use (&$seen): void {
            $seen['after'] = [$first, $second, $third];
        });
    });
    $decision = $engine->decide($panel, $request);

    expect($decision->reason)->not->toBe(DecisionReason::HookError)
        ->and($seen['before'][0])->toBe($request)
        ->and($seen['before'][1])->toBeInstanceOf(EvaluationContext::class)
        ->and($seen['after'][0])->toBe($request)
        ->and($seen['after'][2])->toBe($decision);
});
