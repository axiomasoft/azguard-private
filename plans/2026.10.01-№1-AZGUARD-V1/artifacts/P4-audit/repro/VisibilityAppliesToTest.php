<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\FiltersAccessQueries;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Kernel\Decision\AccessPredicate as P;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RestrictionResult;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use AzGuard\Tests\TestCase;

uses(TestCase::class);

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('A05 exact visibility skips a mandatory restriction whose applicability depends on the resource', function (): void {
    // "Only Paris rows" restriction that applies to every concrete record (scalar has a resource; the list request has none).
    $restriction = new class implements FiltersAccessQueries, Restriction
    {
        public function key(): string { return 'record-city'; }

        public function appliesTo(AccessRequest $request, EvaluationContext $context): bool { return $request->resource() !== null; }

        public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
        {
            return $context->resource()?->getAttribute('city') === 'Paris' ? RestrictionResult::pass() : RestrictionResult::deny();
        }

        public function exemptsSuperAdmin(): bool { return false; }

        public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
        {
            return P::eq('city', 'Paris');
        }
    };
    [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource(direct: [W::grant(1), W::grant(2)]), fn (PanelBuilder $p) => $p->restrictions([$restriction]));
    $scalar = [];
    foreach (VisibilityClient::query()->orderBy('id')->get() as $resource) {
        if ($authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource))->allowed()) {
            $scalar[] = $resource->id;
        }
    }
    $list = $visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->orderBy('id')->pluck('id')->all();
    dump(['scalar' => $scalar, 'list' => $list]);
    expect($list)->toBe($scalar);
});
