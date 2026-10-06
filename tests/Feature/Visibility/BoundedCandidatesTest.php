<?php

declare(strict_types=1);

use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('checks the full PolicyOnly host universe outside any grant prefilter and paginates honest totals', function (): void {
    $source = new VisibilitySource;
    $source->selection = static fn () => throw new RuntimeException('must not read grants');
    [$visibility, $panel] = W::compile($source);
    $page = $visibility->bounded($panel, VisibilityProject::query()->orderBy('id'), W::subject(), 'orders.policy', limit: 4, perPage: 1, page: 2);
    expect($page->total())->toBe(2)->and($page->pluck('id')->all())->toBe([4])->and($source->selectionReads)->toBe(0)->and($source->grantReads)->toBe(0);
});

it('rejects an oversized universe and an already paginated builder', function (): void {
    [$visibility, $panel] = W::compile();
    expect(fn () => $visibility->bounded($panel, VisibilityProject::query(), W::subject(), 'orders.policy', limit: 3))->toThrow(VisibilityNotSupportedException::class, 'bounded_universe_limit')
        ->and(fn () => $visibility->bounded($panel, VisibilityProject::query()->limit(2), W::subject(), 'orders.policy'))->toThrow(VisibilityNotSupportedException::class, 'incomplete_host_universe');
});

it('evaluates every grant candidate before slicing and reports an empty anonymous page', function (): void {
    [$visibility, $panel] = W::compile(new VisibilitySource(direct: [W::grant(1), W::grant(3)]));
    $page = $visibility->bounded($panel, VisibilityProject::query()->orderBy('id'), W::subject(), 'orders.view', limit: 4, perPage: 1, page: 2);
    expect($page->total())->toBe(2)->and($page->pluck('id')->all())->toBe([3])
        ->and($visibility->bounded($panel, VisibilityProject::query(), null, 'orders.view')->total())->toBe(0);
});

it('bounds the full selected-tenant universe before scalar checks and keeps the foreign tenant out of the total', function (): void {
    [$visibility, $panel] = W::compile(tenant: true);
    $page = $visibility->bounded($panel, VisibilityProject::query(), W::subject(), 'orders.policy', W::scope(), limit: 3);
    expect($page->total())->toBe(1)->and($page->pluck('id')->all())->toBe([1]);
});
