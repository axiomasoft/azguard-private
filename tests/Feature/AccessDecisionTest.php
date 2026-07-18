<?php

declare(strict_types=1);

use AzGuard\Events\AccessDecision;
use AzGuard\Guard\Authorizer;
use AzGuard\Registry\Sources\DirectGrantSource;
use AzGuard\Tests\Stubs\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

// F16: explain() describes the verdict off the hot path; the AccessDecision
// event is opt-in via az-guard.audit_log (default off).

it('explains a granted ability without dispatching when audit_log is off', function () {
    Event::fake([AccessDecision::class]);

    $user = $this->createUserWithDirectGrant('test.post.view', 'test');

    $decision = app(Authorizer::class)->explain($user, 'test.post.view');

    expect($decision->allowed)->toBeTrue()
        ->and($decision->reasonCode)->toBe(AccessDecision::SOURCE_GRANT)
        ->and($decision->ability)->toBe('test.post.view')
        ->and($decision->panelId)->toBe('test');

    Event::assertNotDispatched(AccessDecision::class);
});

it('dispatches AccessDecision with the verdict when audit_log is on', function () {
    config()->set('az-guard.features.audit_log', true);
    Event::fake([AccessDecision::class]);

    $user = $this->createUserWithDirectGrant('test.post.view', 'test');

    app(Authorizer::class)->explain($user, 'test.post.view');

    Event::assertDispatched(AccessDecision::class, fn (AccessDecision $e): bool => $e->allowed === true
        && $e->reasonCode === AccessDecision::SOURCE_GRANT
        && $e->ability === 'test.post.view'
        && $e->panelId === 'test'
        && $e->userId === $user->getKey());
});

// C-15: winningSource was always null — explain() computed the decision but
// never attributed WHICH GrantSource produced it.
it('attributes the winning source on a granted ability', function () {
    $user = $this->createUserWithDirectGrant('test.post.view', 'test');

    $decision = app(Authorizer::class)->explain($user, 'test.post.view');

    expect($decision->winningSource)->toBe(DirectGrantSource::class);
});

it('leaves winningSource null on NO_GRANT', function () {
    $user = User::factory()->create();

    $decision = app(Authorizer::class)->explain($user, 'test.post.view');

    expect($decision->winningSource)->toBeNull();
});

it('explains an ungranted ability as NO_GRANT', function () {
    $user = User::factory()->create();

    $decision = app(Authorizer::class)->explain($user, 'test.post.view');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reasonCode)->toBe(AccessDecision::NO_GRANT);
});

it('never emits AccessDecision from the hot check() path, even with audit_log on', function () {
    config()->set('az-guard.features.audit_log', true);
    Event::fake([AccessDecision::class]);

    $user = $this->createUserWithDirectGrant('test.post.view', 'test');

    // A real gate check runs through Authorizer::check(), which must stay silent.
    expect(Gate::forUser($user)->allows('test.post.view'))->toBeTrue();

    Event::assertNotDispatched(AccessDecision::class);
});
