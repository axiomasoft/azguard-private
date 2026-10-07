<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\StateRefresh;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Engines\Support\AuthorityProcess;
use AzGuard\Tests\Engines\Support\CacheEngineWorld;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

beforeEach(fn () => CacheEngineWorld::seed());
afterEach(fn () => CacheEngineWorld::clean());

it('V18 R52 observes independent root commit with Primary check and new request; records request window', function (StateRefresh $refresh): void {
    $worker = new AuthorityProcess;

    try {
        [$engine, $panel] = CacheWorld::database(DatabaseSource::make(), $refresh);
        $before = $engine->decide($panel, DatabaseWorld::request());
        expect($before->allowed())->toBeTrue();
        $commit = $worker->command(['mode' => 'revoke']);
        expect($commit['committed'])->toBeTrue();

        if ($refresh === StateRefresh::Request) {
            expect($engine->decide($panel, DatabaseWorld::request())->allowed())->toBeTrue();
            app()->forgetScopedInstances();
        }
        $after = $engine->decide($panel, DatabaseWorld::request());
        expect($after->reason)->toBe(DecisionReason::NotGranted)
            ->and($after->state->version)->toBe($commit['version'])
            ->and($after->state->version)->toBeGreaterThan($before->state->version);
    } finally {
        $worker->close();
    }
})->with([StateRefresh::Request, StateRefresh::Check])->group('engines');
