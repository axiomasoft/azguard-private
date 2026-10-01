<?php

declare(strict_types=1);

use AzGuard\Context\AuthorizationContext;
use AzGuard\Context\AuthorizationContextManager;

beforeEach(function () {
    $this->manager = new AuthorizationContextManager;
});

it('stores and retrieves context by panelId', function () {
    $ctx = new AuthorizationContext('app', 'workspace', 42);
    $this->manager->set($ctx);

    expect($this->manager->current('app'))->toBe($ctx);
});

it('returns null for unknown panelId', function () {
    expect($this->manager->current('unknown'))->toBeNull();
});

it('has() returns false when context is not set', function () {
    expect($this->manager->has('app'))->toBeFalse();
});

it('has() returns true after set()', function () {
    $this->manager->set(new AuthorizationContext('app', 'workspace', 1));

    expect($this->manager->has('app'))->toBeTrue();
});

it('clear() removes only the specified panel', function () {
    $this->manager->set(new AuthorizationContext('app', 'workspace', 1));
    $this->manager->set(new AuthorizationContext('admin', 'workspace', 2));

    $this->manager->clear('app');

    expect($this->manager->has('app'))->toBeFalse()
        ->and($this->manager->has('admin'))->toBeTrue();
});

it('clearAll() removes all contexts', function () {
    $this->manager->set(new AuthorizationContext('app', 'workspace', 1));
    $this->manager->set(new AuthorizationContext('admin', 'org', 99));

    $this->manager->clearAll();

    expect($this->manager->has('app'))->toBeFalse()
        ->and($this->manager->has('admin'))->toBeFalse();
});

it('set() overwrites previous context for same panelId', function () {
    $first = new AuthorizationContext('app', 'workspace', 1);
    $second = new AuthorizationContext('app', 'workspace', 2);

    $this->manager->set($first);
    $this->manager->set($second);

    expect($this->manager->current('app'))->toBe($second);
});

it('contexts for different panels are independent', function () {
    $app = new AuthorizationContext('app', 'workspace', 1);
    $admin = new AuthorizationContext('admin', 'org', 2);

    $this->manager->set($app);
    $this->manager->set($admin);

    expect($this->manager->current('app'))->toBe($app)
        ->and($this->manager->current('admin'))->toBe($admin);
});
