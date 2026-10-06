<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Tests\Fixtures\Authorization\BatchCrmWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

it('accounts every CRM resource with scalar parity and bounds structural SQL by scopes', function (int $resources, int $contexts): void {
    $expectedIds = BatchCrmWorld::scale($resources, $contexts);
    $panel = World::compile();
    $clients = Client::query()->where('id', '>=', 1000)->orderBy('id')->get();
    $requests = $clients->map(fn (Client $client) => BatchCrmWorld::request($client))->all();
    $budget = ['state' => 0, 'assignments' => 0, 'projects' => 0, 'users' => 0, 'clients' => 0, 'membership' => 0, 'parameters' => 0];
    $measuring = true;
    DB::listen(function (QueryExecuted $query) use (&$budget, &$measuring): void {
        if (! $measuring || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }
        $budget['parameters'] = max($budget['parameters'], count($query->bindings));

        foreach (['azg_panel_state' => 'state', 'azg_permission_grants' => 'assignments', 'azg_role_grants' => 'assignments', 'projects' => 'projects', 'users' => 'users', 'clients' => 'clients', 'organization_user' => 'membership'] as $table => $key) {
            if (preg_match('/\bfrom\s+["`]'.preg_quote($table, '/').'["`]/i', $query->sql)) {
                $budget[$key]++;
            }
        }
    });
    $engine = app(Authorizer::class);
    $set = $engine->decideMany($requests);
    $measuring = false;
    expect($set)->toHaveCount($resources)->and($clients->modelKeys())->toBe(range(1000, 999 + $resources));
    $batchIds = [];

    foreach ($set as $i => $decision) {
        if ($decision->allowed()) {
            $batchIds[] = $clients[$i]->getKey();
        }
    }
    expect($batchIds)->toBe($expectedIds)
        ->and($budget['state'])->toBe(2)->and($budget['assignments'])->toBeLessThanOrEqual(2 * (int) ceil($contexts / 100))
        ->and($budget['clients'])->toBe(0)->and($budget['users'])->toBeLessThanOrEqual(2)
        // Owner/common/native witnesses are bounded by unique scopes and the two role shapes.
        ->and($budget['projects'])->toBeLessThanOrEqual(4 * (int) ceil($contexts / 100))
        ->and($budget['parameters'])->toBeLessThanOrEqual(1000)
        // Membership is a live host predicate; its per-request SQL is accounted separately from structural SQL.
        ->and($budget['membership'])->toBeLessThanOrEqual($resources);
    $scalarIds = [];

    foreach ($requests as $i => $request) {
        $scalar = $engine->decide($panel, $request);
        $batch = $set->get($i);
        $this->assertEquals([$scalar->effect, $scalar->reason, $scalar->scope, $scalar->component], [$batch->effect, $batch->reason, $batch->scope, $batch->component], 'resource id='.$clients[$i]->getKey());
        $this->assertTrue($scalar->state->equals($batch->state), 'resource state id='.$clients[$i]->getKey());

        if ($scalar->allowed()) {
            $scalarIds[] = $clients[$i]->getKey();
        }
    }
    expect($scalarIds)->toBe($expectedIds)->toBe($batchIds);
})->with(['V45 1000 resources' => [1000, 10], 'R58 10000 resources 100 contexts multirole' => [10000, 100]]);
