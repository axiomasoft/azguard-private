<?php

declare(strict_types=1);

use AzGuard\Context\AuthorizationContext;

it('stores all fields correctly', function () {
    $ctx = new AuthorizationContext('app', 'workspace', 42);

    expect($ctx->panelId)->toBe('app')
        ->and($ctx->contextType)->toBe('workspace')
        ->and($ctx->contextId)->toBe(42);
});

it('withPanel() returns new instance with changed panelId', function () {
    $ctx = new AuthorizationContext('app', 'workspace', 42);
    $updated = $ctx->withPanel('admin');

    expect($updated->panelId)->toBe('admin')
        ->and($updated->contextType)->toBe('workspace')
        ->and($updated->contextId)->toBe(42)
        ->and($updated)->not->toBe($ctx);
});

it('withContext() returns new instance with changed type and id', function () {
    $ctx = new AuthorizationContext('app', 'workspace', 42);
    $updated = $ctx->withContext('project', 99);

    expect($updated->contextType)->toBe('project')
        ->and($updated->contextId)->toBe(99)
        ->and($updated->panelId)->toBe('app')
        ->and($updated)->not->toBe($ctx);
});

it('cacheKey() returns panelId:contextType:contextId', function () {
    $ctx = new AuthorizationContext('app', 'workspace', 42);

    expect($ctx->cacheKey())->toBe('app:workspace:42');
});

it('cacheKey() works with string contextId', function () {
    $ctx = new AuthorizationContext('app', 'tenant', 'acme-corp');

    expect($ctx->cacheKey())->toBe('app:tenant:acme-corp');
});

it('equals() returns true for identical contexts', function () {
    $a = new AuthorizationContext('app', 'workspace', 42);
    $b = new AuthorizationContext('app', 'workspace', 42);

    expect($a->equals($b))->toBeTrue();
});

it('equals() returns false when contextId differs', function () {
    $a = new AuthorizationContext('app', 'workspace', 42);
    $b = new AuthorizationContext('app', 'workspace', 99);

    expect($a->equals($b))->toBeFalse();
});

it('equals() returns false when panelId differs', function () {
    $a = new AuthorizationContext('app', 'workspace', 42);
    $b = new AuthorizationContext('admin', 'workspace', 42);

    expect($a->equals($b))->toBeFalse();
});

it('equals() returns false when contextType differs', function () {
    $a = new AuthorizationContext('app', 'workspace', 42);
    $b = new AuthorizationContext('app', 'project', 42);

    expect($a->equals($b))->toBeFalse();
});

it('accepts string contextId', function () {
    $ctx = new AuthorizationContext('app', 'tenant', 'acme-corp');

    expect($ctx->contextId)->toBe('acme-corp');
});
