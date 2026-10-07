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

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

/**
 * "Only Paris rows" restriction with an adapter. Its applicability is encoded in predicate();
 * appliesTo() answers differently for a list request (no resource) and must not matter there.
 */
function appliesToRestriction(string $mode): Restriction&FiltersAccessQueries
{
    return new class($mode) implements FiltersAccessQueries, Restriction
    {
        public function __construct(private string $mode) {}

        public function key(): string
        {
            return 'record-city';
        }

        public function appliesTo(AccessRequest $request, EvaluationContext $context): bool
        {
            return match ($this->mode) {
                'resource' => $request->resource() !== null,
                'permission' => $request->permission()->local() === 'orders.view',
                default => false,
            };
        }

        public function check(AccessRequest $request, EvaluationContext $context): RestrictionResult
        {
            return $context->resource()?->getAttribute('city') === 'Paris' ? RestrictionResult::pass() : RestrictionResult::deny();
        }

        public function exemptsSuperAdmin(): bool
        {
            return false;
        }

        public function predicate(AccessRequest $request, string $resourceType, EvaluationContext $context, Grant|RoleContribution|null $contribution = null): P
        {
            $applicable = match ($this->mode) {
                'inapplicable' => false,
                'permission' => $request->permission()->local() === 'orders.view',
                default => true,
            };

            return $applicable ? P::eq('city', 'Paris') : P::pass();
        }
    };
}

/** @return array{list<int>, list<int>} */
function scalarAndListIds(string $mode, string $permission = 'orders.view'): array
{
    [$visibility, $panel, $authorizer] = W::compile(
        new VisibilitySource(direct: [W::grant(1), W::grant(2)]),
        fn (PanelBuilder $p) => $p->restrictions([appliesToRestriction($mode)]),
    );
    $scalar = [];

    foreach (VisibilityClient::query()->orderBy('id')->get() as $resource) {
        if ($authorizer->decide($panel, AccessRequest::for(W::subject(), PermissionKey::of('admin', $permission))->on(null, $resource))->allowed()) {
            $scalar[] = $resource->id;
        }
    }

    return [$scalar, $visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), $permission)->orderBy('id')->pluck('id')->all()];
}

it('A05 keeps a resource-dependent appliesTo of an adapter restriction from widening the list', function (): void {
    [$scalar, $list] = scalarAndListIds('resource');
    expect($scalar)->toBe([101])->and($list)->toBe([101]);
});

it('A05 compiles the predicate of an adapter restriction whose appliesTo is false for the list request', function (): void {
    [$scalar, $list] = scalarAndListIds('permission', 'orders.view');
    expect($scalar)->toBe([101])->and($list)->toBe([101]);
});

it('A05 lets an adapter restriction express inapplicability through a pass predicate', function (): void {
    [$scalar, $list] = scalarAndListIds('inapplicable');
    expect($scalar)->toBe([101, 102])->and($list)->toBe([101, 102]);
});

it('A05 encodes applicability by permission in predicate so list equals scalar for another permission', function (string $permission): void {
    [$scalar, $list] = scalarAndListIds('permission', $permission);
    expect($list)->toBe($scalar);
})->with(['orders.view', 'orders.policy']);
