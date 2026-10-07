<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Tests\Fixtures\Scopes\ScopeSource;
use AzGuard\Tests\Fixtures\Scopes\ScopeWorld;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

uses(TestCase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
});
afterEach(function (): void {
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('A01 singleton Authorizer keeps reading the CurrentContext of a previous scoped lifecycle', function (): void {
    // Grant only in tenant A.
    $source = new ScopeSource(direct: [ScopeWorld::grant(ScopeWorld::scope('A'))]);
    [$engine, $panel, $request] = ScopeWorld::compile($source);
    expect($engine)->toBe(app(Authorizer::class));

    // "Request/job 1": ambient tenant A.
    app(CurrentContext::class)->set($panel, ScopeWorld::scope('A'));
    $first = app(Authorizer::class)->decide($panel, $request);
    dump(['first' => [$first->allowed(), $first->reason->value, $first->scope->tenant->key()]]);

    // Octane request / queue job boundary: Laravel flushes scoped instances.
    app()->forgetScopedInstances();

    // "Request/job 2": ambient tenant B (no grant there).
    app(CurrentContext::class)->set($panel, ScopeWorld::scope('B'));
    $second = app(Authorizer::class)->decide($panel, $request);
    dump(['second' => [$second->allowed(), $second->reason->value, $second->scope->tenant->key()]]);

    expect($second->scope->tenant->key())->toBe(ScopeWorld::scope('B')->tenant->key())
        ->and($second->allowed())->toBeFalse();
});
