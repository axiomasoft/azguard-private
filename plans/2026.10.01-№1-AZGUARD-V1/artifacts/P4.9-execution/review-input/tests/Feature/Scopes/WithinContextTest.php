<?php

declare(strict_types=1);

use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Exceptions\InvalidAssignmentScopeException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Scopes\WithinContext;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\Project;
use AzGuard\Tests\Fixtures\Scopes\ScopeWorld;
use AzGuard\Tests\Fixtures\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Relations\Relation;

afterEach(fn () => Relation::morphMap([], false));

it('restores nested per-panel scopes on success and callback exception without selecting a panel', function (bool $throws): void {
    [, $panel] = ScopeWorld::compile(new GeneratedSource);
    [, , $registry] = PanelWorld::compile([CabinetPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)]);
    $selectedPanel = $registry->get('cabinet');
    $current = app(CurrentContext::class);
    $panels = app(CurrentPanel::class);
    $current->set($panel, ScopeWorld::scope());
    $current->set($selectedPanel, AccessScope::in(TenantRef::global()));
    $panels->set($selectedPanel);
    $within = new WithinContext($current);

    $outer = function () use ($within, $current, $panels, $panel, $selectedPanel, $throws): string {
        expect($current->get($panel)?->equals(ScopeWorld::scope(context: 1)))->toBeTrue()->and($panels->get())->toBe($selectedPanel);
        $inner = function () use ($current, $panels, $panel, $selectedPanel, $throws): string {
            expect($current->get($panel)?->equals(ScopeWorld::scope('B', 2)))->toBeTrue()
                ->and($panels->get())->toBe($selectedPanel)->and($current->get($selectedPanel)?->tenant->isGlobal())->toBeTrue();

            if ($throws) {
                throw new RuntimeException('callback failed');
            }

            return 'result';
        };

        if ($throws) {
            expect(fn () => $within->run($panel, ScopeWorld::scope('B', 2), $inner))->toThrow(RuntimeException::class, 'callback failed');
        } else {
            expect($within->run($panel, ScopeWorld::scope('B', 2), $inner))->toBe('result');
        }

        expect($current->get($panel)?->equals(ScopeWorld::scope(context: 1)))->toBeTrue()->and($panels->get())->toBe($selectedPanel);

        return 'outer';
    };
    expect($within->run($panel, ScopeWorld::scope(context: 1), $outer))->toBe('outer')
        ->and($current->get($panel)?->equals(ScopeWorld::scope()))->toBeTrue()->and($panels->get())->toBe($selectedPanel)
        ->and($current->get($selectedPanel)?->tenant->isGlobal())->toBeTrue();
})->with([false, true]);

it('rejects forged structural entry identity and restores the prior scope', function (string $case): void {
    $definition = new StoreScope(function (AssignmentScopeRef $ref) use ($case): ResolvedAssignmentScope {
        $record = null;

        if ($case === 'record') {
            $record = new Project;
            $record->setAttribute('id', 2);
        }

        return new ResolvedAssignmentScope($case === 'ref' ? AssignmentScopeRef::of('store', 2) : $ref, TenantRef::of('org', $case === 'tenant' ? 'B' : 'A'), $record);
    });
    [, $panel] = ScopeWorld::compile(new GeneratedSource, definition: $definition);
    $current = app(CurrentContext::class);
    $panels = app(CurrentPanel::class);
    $current->set($panel, ScopeWorld::scope());
    expect(fn () => (new WithinContext($current))->run($panel, ScopeWorld::scope(context: 1), fn (): string => 'never'))
        ->toThrow(InvalidAssignmentScopeException::class)->and($current->get($panel)?->equals(ScopeWorld::scope()))->toBeTrue()->and($panels->get())->toBeNull();
})->with(['ref', 'tenant', 'record']);

it('restores the explicit panels scope when entry resolution throws or rejects and preserves the current panel', function (bool $throws): void {
    $definition = new StoreScope(function () use ($throws): ?ResolvedAssignmentScope {
        expect(app(CurrentPanel::class)->get()?->id())->toBe('cabinet');

        if ($throws) {
            throw new RuntimeException('entry failed');
        }

        return null;
    });
    [, $panel] = ScopeWorld::compile(new GeneratedSource, definition: $definition);
    [, , $registry] = PanelWorld::compile([CabinetPanel::class => fn (PanelBuilder $panel) => $panel->for(User::class)]);
    $previousPanel = $registry->get('cabinet');
    $current = app(CurrentContext::class);
    $panels = app(CurrentPanel::class);
    $panels->set($previousPanel);
    $current->set($panel, ScopeWorld::scope());
    $current->set($previousPanel, AccessScope::in(TenantRef::global()));
    $callbackCalls = 0;
    $callback = function () use (&$callbackCalls): void {
        $callbackCalls++;
    };
    expect(fn () => (new WithinContext($current))->run($panel, ScopeWorld::scope(context: 1), $callback))
        ->toThrow($throws ? RuntimeException::class : InvalidAssignmentScopeException::class)
        ->and($callbackCalls)->toBe(0)->and($panels->get())->toBe($previousPanel)
        ->and($current->get($panel)?->equals(ScopeWorld::scope()))->toBeTrue()
        ->and($current->get($previousPanel)?->tenant->isGlobal())->toBeTrue();
})->with([false, true]);
