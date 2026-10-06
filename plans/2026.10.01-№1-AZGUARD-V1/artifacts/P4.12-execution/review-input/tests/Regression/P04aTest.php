<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Support\Facades\Auth;

afterEach(fn () => W::reset());

it('P04a returns no rows for an explicit null subject without reading Auth', function (): void {
    W::seed();
    [$visibility, $panel] = W::compile();
    Auth::shouldReceive('user')->never();
    expect($visibility->visibleTo($panel, VisibilityProject::query(), null, 'orders.view')->count())->toBe(0);
});
