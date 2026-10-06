<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;

afterEach(fn () => W::reset());

it('P04b returns no rows when an existing subject has no grant or global right', function (): void {
    W::seed();
    [$visibility, $panel] = W::compile();
    expect($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->count())->toBe(0);
});
