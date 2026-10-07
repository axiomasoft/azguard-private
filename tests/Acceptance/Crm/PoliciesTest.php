<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission as Action;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Policies\Clients\ClientPolicy;
use Illuminate\Auth\Access\Response;

it('R21 caller can update normal C1 while policy vetoes do not call C2', function (): void {
    World::clear();
    World::assign('caller', 1, 1);
    $panel = World::compile();
    World::assertDecision(World::decide($panel, 1, Action::Update), true, DecisionReason::Granted);
    World::assertDecision(World::decide($panel, 2, Action::Update), false, DecisionReason::Policy, ClientPolicy::class);
    foreach ([1, 2] as $id) {
        World::assertDecision(World::decide($panel, $id), true, DecisionReason::Granted);
    }
    World::assertDecision(World::decide($panel, 3), false, DecisionReason::NotGranted);
});

it('R22 policy modes distinguish null true false and Response with and without grants', function (string $kind): void {
    World::clear();
    ClientPolicy::$override = true;
    ClientPolicy::$result = match ($kind) {
        'null' => null, 'true' => true, 'false' => false, 'allow' => Response::allow(),
        default => Response::deny('Do not call', 'crm_veto')->withStatus(409),
    };
    $panel = World::compile();
    $policyAllows = in_array($kind, ['true', 'allow'], true);
    $veto = in_array($kind, ['false', 'deny'], true);
    World::assertDecision(World::decide($panel, action: Action::ViewOwnProfile), $policyAllows, DecisionReason::Policy);
    World::assertDecision(World::decide($panel), false, DecisionReason::NotGranted);
    World::assign('clients.view', 1, 1, kind: 'permission');
    $decision = World::decide($panel);
    World::assertDecision($decision, ! $veto, $veto ? DecisionReason::Policy : DecisionReason::Granted);

    if ($kind === 'deny') {
        expect($decision->message)->toBe('Do not call')->and($decision->status)->toBe(409)->and($decision->code)->toBe('crm_veto');
    }
    ClientPolicy::$result = true;
    World::assertDecision(World::decide($panel, action: Action::ViewOwnProfile), true, DecisionReason::Policy);
})->with(['null', 'true', 'false', 'allow', 'deny']);
