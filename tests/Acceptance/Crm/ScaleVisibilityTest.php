<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Authorization\BatchCrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Queries\Clients\ClientVisibility as Lists;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * R58 is 10000 clients in 100 scopes: a minute on a server database, so it runs in the `extended` group
 * (`composer test:extended`, nightly CI). The same budgets are asserted at 1000 clients in 10 scopes on every run.
 */
function returnsEveryCrmIdWithinTheBudget(int $clients, int $scopes): void
{
    $ids = BatchCrmWorld::scale($clients, $scopes);
    $panel = Lists::panel();
    $queries = [];
    $measuring = true;
    DB::listen(function (QueryExecuted $query) use (&$queries, &$measuring): void {
        if ($measuring && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $queries[] = ['sql' => $query->sql, 'parameters' => count($query->bindings)];
        }
    });
    $query = Lists::query($panel);
    expect((clone $query)->orderBy('id')->pluck('id')->all())->toBe($ids)->and((clone $query)->count())->toBe(count($ids));
    $page = (clone $query)->orderBy('id')->paginate(37, page: 13);
    expect($page->total())->toBe(count($ids))->and($page->pluck('id')->all())->toBe(array_slice($ids, 12 * 37, 37));
    $measuring = false;
    $resources = array_filter($queries, fn (array $q) => preg_match('/\bfrom ["`]clients["`]/', $q['sql']));
    $assignments = array_filter($queries, fn (array $q) => preg_match('/\bfrom ["`]azg_(role|permission)_grants["`]/', $q['sql']));
    expect($resources)->toHaveCount(4)->and($assignments)->toHaveCount(2)
        ->and(count($queries))->toBeLessThanOrEqual(20)
        ->and(max(array_column($queries, 'parameters')))->toBeLessThanOrEqual(5000);

    if ($artifactDir = getenv('VISIBILITY_EVIDENCE_DIR')) {
        file_put_contents($artifactDir.'/scale-budget.json', json_encode(['clients' => $clients, 'scopes' => $scopes, 'roles' => 2, 'allowed' => count($ids),
            'selects' => count($queries), 'assignment_selects' => count($assignments), 'resource_selects' => count($resources),
            'selection_chunk_size' => 500, 'max_parameters' => max(array_column($queries, 'parameters')),
            'max_sql_bytes' => max(array_map(fn (array $q) => strlen($q['sql']), $queries))], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}

it('V45 returns all 1000 CRM ids and honest totals with SQL bounded by 10 scopes and two roles', fn () => returnsEveryCrmIdWithinTheBudget(1000, 10));

it('R58 returns all 10000 CRM ids and honest totals with SQL bounded by 100 scopes and two roles', fn () => returnsEveryCrmIdWithinTheBudget(10000, 100))
    ->group('extended', 'stand');
