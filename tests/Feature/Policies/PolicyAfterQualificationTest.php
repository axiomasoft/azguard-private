<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Guards\Orders\OrderGrantSource;
use AzGuard\Tests\Fixtures\Guards\Orders\OrderWorld;
use AzGuard\Tests\Fixtures\Guards\Orders\Permissions\OrderPermission;
use AzGuard\Tests\Fixtures\Guards\Orders\Policies\OrderPolicy;
use AzGuard\Tests\Fixtures\Guards\Orders\Policies\WorkingHoursPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach(fn () => OrderWorld::seed());
afterEach(fn () => Relation::morphMap([], false));

dataset('policy outcomes', [
    'true' => fn () => true,
    'false' => fn () => false,
    'null' => fn () => null,
    'deny response' => fn () => Response::deny('Closed', 'orders.closed')->withStatus(409),
    'allow response' => fn () => Response::allow(),
    'invalid return' => fn () => 'invalid',
    'throwing response' => fn () => throwingPolicyResponse(),
]);

function throwingPolicyResponse(): Response
{
    return new class extends Response
    {
        public function __construct()
        {
            parent::__construct(false);
        }

        public function message(): ?string
        {
            throw new RuntimeException('policy exploded');
        }
    };
}

/** @return array<string, Decision> */
function policyDecisionsAcrossPaths(): array
{
    $registry = app(PanelRegistry::class);
    $panel = $registry->get('orders');
    $request = OrderWorld::request();
    $authorizer = app(Authorizer::class);

    return [
        'decide' => $authorizer->decide($panel, $request),
        'decideMany' => $authorizer->decideMany([$request])->get(0),
        'explain' => $authorizer->explain($panel, $request)->decision(),
    ];
}

it('never calls a grants policy for a subject without a qualifying contribution', function (mixed $result): void {
    OrderWorld::compile();
    WorkingHoursPolicy::$result = $result;

    foreach (policyDecisionsAcrossPaths() as $path => $decision) {
        expect($decision->reason)->toBe(DecisionReason::NotGranted, $path)
            ->and($decision->allowed())->toBeFalse()
            ->and($decision->component)->toBeNull();
    }
    expect(WorkingHoursPolicy::$calls)->toBe(0);
})->with('policy outcomes');

it('marks the policy step skipped when no contribution qualifies', function (): void {
    OrderWorld::compile();
    WorkingHoursPolicy::$result = false;
    $explanation = app(Authorizer::class)->explain(app(PanelRegistry::class)->get('orders'), OrderWorld::request());
    $steps = collect($explanation->steps())->keyBy('stage');
    expect($explanation->decision()->reason)->toBe(DecisionReason::NotGranted)
        ->and($steps['policy']['outcome'])->toBe('skipped')
        ->and($steps['authority']['outcome'])->toBe('deny')
        ->and(WorkingHoursPolicy::$calls)->toBe(0);
});

it('still calls the policy exactly once for a qualified subject', function (): void {
    OrderWorld::compile([new OrderGrantSource([OrderWorld::grant()])]);
    WorkingHoursPolicy::$result = false;

    foreach (policyDecisionsAcrossPaths() as $path => $decision) {
        expect($decision->reason)->toBe(DecisionReason::Policy, $path)->and($decision->component)->toBe(WorkingHoursPolicy::class);
    }
    expect(WorkingHoursPolicy::$calls)->toBe(3);
});

it('reports a policy failure only after qualification', function (mixed $result): void {
    OrderWorld::compile([new OrderGrantSource([OrderWorld::grant()])]);
    WorkingHoursPolicy::$result = $result;

    foreach (policyDecisionsAcrossPaths() as $path => $decision) {
        expect($decision->reason)->toBe(DecisionReason::PolicyError, $path)->and($decision->allowed())->toBeFalse();
    }
})->with(['invalid return' => fn () => 'invalid', 'throwing response' => fn () => throwingPolicyResponse()]);

it('keeps the policy call for the policy authority regardless of assignments', function (): void {
    OrderWorld::compile();
    OrderPolicy::$result = false;
    $decision = OrderWorld::decide(OrderWorld::request(OrderPermission::UserOnly));
    expect($decision->reason)->toBe(DecisionReason::Policy)->and($decision->allowed())->toBeFalse()
        ->and($decision->component)->toBe(OrderPolicy::class);
});
