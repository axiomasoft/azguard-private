<?php

declare(strict_types=1);

use AzGuard\Context\AuthorizationContextManager;
use AzGuard\Context\Contracts\ResolvesContext;
use AzGuard\Context\Middleware\SetAuthorizationContext;
use AzGuard\Tests\Stubs\Context\StaticWorkspaceResolver;
use Illuminate\Support\Facades\Route;

/**
 * F14 — the 'azguard.context' middleware alias is auto-registered by
 * AzGuardContextServiceProvider::boot(). A route may reference it by the
 * string alias with NO manual aliasMiddleware() wiring in bootstrap/app.php
 * (the previous silent trap: the alias lived only in a docblock example).
 */
beforeEach(function (): void {
    $this->manager = app(AuthorizationContextManager::class);
    $this->manager->clearAll();
});

it('auto-registers the azguard.context alias to the middleware class', function (): void {
    $aliases = app('router')->getMiddleware();

    expect($aliases)->toHaveKey('azguard.context')
        ->and($aliases['azguard.context'])->toBe(SetAuthorizationContext::class);
});

it('sets the context on the manager when a route uses the string alias', function (): void {
    // A resolver is tagged so the middleware has something to resolve.
    app()->bind(StaticWorkspaceResolver::class);
    app()->tag(StaticWorkspaceResolver::class, ResolvesContext::class);

    Route::middleware('azguard.context')->get('/context-alias-test', function (): string {
        $ctx = app(AuthorizationContextManager::class)->current('test');

        return $ctx === null
            ? 'none'
            : "{$ctx->contextType}:{$ctx->contextId}";
    });

    $this->get('/context-alias-test')
        ->assertOk()
        ->assertSee('workspace:42');
});

it('leaves the context unset when no resolver applies', function (): void {
    // No resolver tagged: middleware runs but sets nothing.
    Route::middleware('azguard.context')->get('/context-alias-empty', function (): string {
        return app(AuthorizationContextManager::class)->has('test') ? 'set' : 'unset';
    });

    $this->get('/context-alias-empty')
        ->assertOk()
        ->assertSee('unset');
});
