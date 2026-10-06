<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission as Action;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('R01 reads literal clients in the selected tenant through actual DB assignments', function (): void {
    $panel = World::compile();
    foreach ([[1, 1], [2, 1], [3, 1], [5, 2]] as [$client, $tenant]) {
        World::assertDecision(World::decide($panel, $client, tenant: $tenant), true, DecisionReason::Granted);
    }
    World::assertDecision(World::decide($panel, 5), false, DecisionReason::TenantMismatch);
    World::assertDecision(World::decide($panel, 6), false, DecisionReason::NotGranted);
    foreach ([1 => [1, 2, 3], 2 => [5]] as $tenant => $expected) {
        $allowed = [];
        foreach ([1, 2, 3, 4, 5, 6] as $id) {
            if (World::decide($panel, $id, tenant: $tenant)->allowed()) {
                $allowed[] = $id;
            }
        }
        expect($allowed)->toBe($expected);
    }
});

it('R02 keeps CRM assignments separate from a positive backoffice assignment', function (): void {
    World::compile();
    $panel = app(PanelRegistry::class)->get('backoffice');
    World::assertDecision(World::decide($panel), false, DecisionReason::NotGranted);
    World::assign('seller', 1, 1, panel: 'backoffice');
    World::assertDecision(World::decide($panel), true, DecisionReason::Granted);
});

it('R06 enforces membership unknown tenants and resource ownership even for policy and admin', function (string $case, string $authority): void {
    $panel = World::compile();
    $user = $authority === 'admin' ? 3 : 1;
    $action = $authority === 'policy' ? Action::ViewOwnProfile : Action::View;
    $client = Client::query()->findOrFail(1);
    $client->setAttribute('owner_user_id', $user);
    World::assertDecision(World::decide($panel, $client, $action, $user), true, $authority === 'admin' ? DecisionReason::SuperAdmin : DecisionReason::Policy);
    $tenant = 1;
    $reason = DecisionReason::TenantMismatch;

    if ($case === 'outsider') {
        $reason = DecisionReason::Restricted;
        $user = 4;
        $client->setAttribute('owner_user_id', 4);
        World::assign('tenant-admin', 4, 1);
    } elseif ($case === 'removed') {
        $reason = DecisionReason::Restricted;
        DB::table('organization_user')->where('user_id', $user)->delete();
    } elseif ($case === 'unknown') {
        $tenant = 99;
    } else {
        $client->setAttribute('organization_id', 2);
    }
    World::assertDecision(World::decide($panel, $client, $action, $user, $tenant), false, $reason);
})->with(['outsider', 'removed', 'unknown', 'forged'])->with(['policy', 'admin']);

it('R06 rejects an owner forged to the selected tenant while the project belongs to B', function (): void {
    $panel = World::compile();
    $client = Client::query()->findOrFail(5);
    World::assertDecision(World::decide($panel, $client, tenant: 2), true, DecisionReason::Granted);
    $client->setAttribute('organization_id', 1);
    World::assertDecision(World::decide($panel, $client), false, DecisionReason::AssignmentScopeMismatch);
});

it('R07 host composite FK rejects a cross tenant client without changing the original row', function (): void {
    expect(fn () => Client::query()->whereKey(1)->update(['project_id' => 4]))
        ->toThrow(QueryException::class);
    expect(Client::query()->findOrFail(1)->getAttribute('project_id'))->toBe(1);
});
