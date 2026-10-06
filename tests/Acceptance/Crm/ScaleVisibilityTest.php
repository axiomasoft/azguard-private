<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Authorization\BatchCrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Queries\Clients\ClientVisibility as Lists;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

it('R58 returns all 10000 CRM ids and honest totals with SQL bounded by 100 scopes and two roles', function (): void {
    $ids = BatchCrmWorld::scale(10000, 100);
    $panel = Lists::panel();
    $queries = [];
    $measuring = true;
    DB::listen(function (QueryExecuted $query) use (&$queries, &$measuring): void {
        if ($measuring && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $queries[] = ['sql' => $query->sql, 'parameters' => count($query->bindings)];
        }
    });
    $query = Lists::query($panel);
    expect((clone $query)->orderBy('id')->pluck('id')->all())->toBe($ids)->and((clone $query)->count())->toBe(8000);
    $page = (clone $query)->orderBy('id')->paginate(37, page: 13);
    expect($page->total())->toBe(8000)->and($page->pluck('id')->all())->toBe(array_slice($ids, 12 * 37, 37));
    $measuring = false;
    $resources = array_filter($queries, fn (array $q) => preg_match('/\bfrom ["`]clients["`]/', $q['sql']));
    $assignments = array_filter($queries, fn (array $q) => preg_match('/\bfrom ["`]azg_(role|permission)_grants["`]/', $q['sql']));
    expect($resources)->toHaveCount(4)->and($assignments)->toHaveCount(2)
        ->and(count($queries))->toBeLessThanOrEqual(20)
        ->and(max(array_column($queries, 'parameters')))->toBeLessThanOrEqual(5000);

    if ($artifactDir = getenv('VISIBILITY_EVIDENCE_DIR')) {
        file_put_contents($artifactDir.'/scale-budget.json', json_encode(['clients' => 10000, 'scopes' => 100, 'roles' => 2, 'allowed' => count($ids),
            'selects' => count($queries), 'assignment_selects' => count($assignments), 'resource_selects' => count($resources),
            'selection_chunk_size' => 500, 'max_parameters' => max(array_column($queries, 'parameters')),
            'max_sql_bytes' => max(array_map(fn (array $q) => strlen($q['sql']), $queries))], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
});
