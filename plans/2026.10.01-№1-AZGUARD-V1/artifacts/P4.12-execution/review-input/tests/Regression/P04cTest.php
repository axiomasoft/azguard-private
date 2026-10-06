<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;

afterEach(fn () => W::reset());

it('P04c ORs a role in two projects while hiding the unassigned third project', function (): void {
    W::seed();
    [$visibility, $panel] = W::compile(new VisibilitySource(roles: [W::role(1), W::role(2)]));
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all())->toBe([1, 2]);
});
