<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;

uses(TestCase::class);

afterEach(fn () => Relation::morphMap([], false));

it('A01b singleton Authorizer resolves the panel from a CurrentPanel of a previous scoped lifecycle', function (): void {
    Relation::morphMap(['user' => User::class], false);
    [, , $registry] = PanelWorld::adminAndCabinet();
    app()->instance(PanelRegistry::class, $registry);
    app()->forgetInstance(Authorizer::class);
    $subject = SubjectRef::of('user', 1);

    app(CurrentPanel::class)->set($registry->get('admin'));
    [$first] = app(Authorizer::class)->resolve($subject, 'orders.view');

    app()->forgetScopedInstances(); // next Octane request / queue job
    app(CurrentPanel::class)->set($registry->get('cabinet'));
    [$second] = app(Authorizer::class)->resolve($subject, 'orders.view');
    dump(['first' => $first->id(), 'second' => $second->id()]);

    expect($second->id())->toBe('cabinet');
});
