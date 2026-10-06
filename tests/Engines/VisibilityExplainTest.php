<?php

declare(strict_types=1);

use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Authorization\BatchCrmWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld as World;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission as Action;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Policies\Clients\ClientPolicy;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Queries\Clients\ClientVisibility as Lists;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function dropVisibilityEngineFixture(): void
{
    app(StorageSchema::class)->drop('default');
    foreach (['clients', 'project_members', 'organization_user', 'projects', 'users', 'cities', 'organizations'] as $table) {
        Schema::dropIfExists($table);
    }
}

/** @return list<string> Actual chosen keys only; possible_keys are not execution evidence. */
function visibilityChosenIndexes(array $plan): array
{
    $indexes = [];
    foreach ($plan as $key => $value) {
        if (in_array($key, ['key', 'Index Name'], true) && is_string($value)) {
            $indexes[] = $value;
        } elseif (is_array($value)) {
            $indexes = [...$indexes, ...visibilityChosenIndexes($value)];
        }
    }

    return $indexes;
}

beforeEach(function (): void {
    dropVisibilityEngineFixture();
    World::seed();
});

afterEach(function (): void {
    dropVisibilityEngineFixture();
    World::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('P14 verifies 100 seeded CRM scalar list count snapshots on the actual engine', function (): void {
    for ($seed = 1; $seed <= 100; $seed++) {
        World::clear();
        $state = $seed;
        $draw = static function (int $max) use (&$state): int {
            $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;

            return ($state >> 8) % $max;
        };
        for ($i = 0; $i < 6; $i++) {
            World::assign(['seller', 'analyst', 'caller'][$draw(3)], 1, $draw(5) + 1,
                fields: ['region' => ['R1', 'R2', null][$draw(3)], 'eligible' => $draw(3) !== 0], origin: 'seed-'.$i);
        }
        ClientPolicy::$override = $seed % 3 !== 0;
        ClientPolicy::$result = [true, false, null][$seed % 3];
        $panel = Lists::panel();
        World::storage()->withinAuthorityTransaction('crm', function () use ($seed, $panel): void {
            foreach ([Action::View, Action::Update, Action::ViewOwnProfile] as $action) {
                $scalar = Client::query()->orderBy('id')->get()->filter(fn (Client $client) => World::decide($panel, $client, $action)->allowed())->values()->modelKeys();
                $query = Lists::query($panel, $action);
                $this->assertSame($scalar, (clone $query)->orderBy('id')->pluck('id')->all(), "seed=$seed action={$action->value}");
                $this->assertSame(count($scalar), $query->count(), "seed=$seed count={$action->value}");
            }
        });
    }
})->group('engines', 'visibility-engines');

it('V33 R58 explains real authority and resource SQL on 100000 clients using physical composite indexes', function (): void {
    $ids = BatchCrmWorld::scale(100000, 100);
    Schema::table('clients', fn (Blueprint $table) => $table->index(['organization_id', 'project_id'], 'crm_clients_tenant_project'));
    $storage = World::storage();
    // Distractor subjects make the real composite subject index selective, without hints.
    $storage->mutate('crm', function ($mutation): void {
        $rows = [];
        for ($i = 2; $i <= 10001; $i++) {
            $scope = World::scope(1, 100 + $i % 100);
            $rows[] = ['panel' => 'crm', 'tenant_key' => $scope->tenant->key(), 'tenant_type' => $scope->tenant->type(), 'tenant_id' => $scope->tenant->id(),
                'context_key' => $scope->context->key(), 'context_type' => $scope->context->type(), 'context_id' => $scope->context->id(),
                'subject_type' => 'crm.user', 'subject_id' => (string) $i, 'role' => 'seller', 'origin' => 'manual'];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            $mutation->table('role_grants')->insert($chunk);
        }
        $mutation->touch('crm');
    });
    $driver = DB::connection()->getDriverName();

    if ($driver === 'pgsql') {
        DB::statement('ANALYZE azg_role_grants');
        DB::statement('ANALYZE projects');
        DB::statement('ANALYZE clients');
    } else {
        DB::select('ANALYZE TABLE azg_role_grants, projects, clients');
    }
    $panel = Lists::panel();
    $queries = [];
    $measuring = true;
    DB::listen(function (QueryExecuted $event) use (&$queries, &$measuring): void {
        if ($measuring && preg_match('/\bfrom ["`]azg_role_grants["`]/', $event->sql)) {
            $queries[] = ['sql' => $event->sql, 'bindings' => $event->bindings];
        }
    });
    $query = Lists::query($panel);
    $measuring = false;
    expect($query->count())->toBe(count($ids))->toBe(80000);
    $selective = clone $query;
    $selective->where('clients.organization_id', 1)->where('clients.project_id', 101);
    expect($selective->count())->toBe(1000);
    $plans = [];
    foreach (['authority' => $queries[0], 'resources' => ['sql' => $query->toSql(), 'bindings' => $query->getBindings()],
        'host-index' => ['sql' => $selective->toSql(), 'bindings' => $selective->getBindings()]] as $name => $sql) {
        $rows = DB::select(($driver === 'pgsql' ? 'EXPLAIN (FORMAT JSON) ' : 'EXPLAIN FORMAT=JSON ').$sql['sql'], $sql['bindings']);
        $plans[$name] = ['query' => $sql, 'plan' => json_decode(array_values((array) $rows[0])[0], true, flags: JSON_THROW_ON_ERROR)];
    }
    $authority = visibilityChosenIndexes($plans['authority']['plan']);
    $host = visibilityChosenIndexes($plans['host-index']['plan']);
    $hostIndexes = array_column(array_filter(Schema::getIndexes('clients'),
        fn (array $index) => in_array('organization_id', $index['columns'], true) && in_array('project_id', $index['columns'], true)), 'name');
    expect($authority)->toContain('azg_rg_subject')->and(array_intersect($host, $hostIndexes))->not->toBe([]);
    $indexes = Schema::getIndexes('azg_role_grants');
    $subject = array_values(array_filter($indexes, fn (array $index) => $index['name'] === 'azg_rg_subject'));
    expect($subject[0]['columns'])->toBe(['panel', 'tenant_key', 'subject_type', 'subject_id', 'context_key']);

    if ($artifactDir = getenv('VISIBILITY_EVIDENCE_DIR')) {
        file_put_contents($artifactDir.'/explain-'.$driver.'.json', json_encode(['clients' => 100000, 'scopes' => 100,
            'allowed' => count($ids), 'max_parameters' => count($query->getBindings()), 'indexes' => $indexes, 'plans' => $plans], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
})->group('engines', 'visibility-engines');
