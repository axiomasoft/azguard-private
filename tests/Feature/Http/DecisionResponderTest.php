<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Laravel\Http\DecisionFailedException;
use AzGuard\Laravel\Http\DecisionResponder;
use Illuminate\Support\Facades\Route;

// The reference HTTP adapter (audits/2026-10-09-consistency-design.md, step 6): 403 deny, 503 transient failure,
// 500 contract failure, and no internals in the response.

function responderDecision(DecisionReason $reason): Decision
{
    $state = CodeStateToken::of('admin', 'build', str_repeat('b', 64));
    $scope = AccessScope::in(TenantRef::global());

    return match ($reason) {
        DecisionReason::Granted => Decision::allow($reason, $state, $scope),
        default => Decision::deny($reason, $state, $scope, 'App\\Secret\\Component'),
    };
}

it('maps a decision to 403, 503 or 500 and allows nothing else', function (DecisionReason $reason, ?int $status): void {
    config(['app.debug' => false]);
    Route::get('/responder', static function () use ($reason): string {
        DecisionResponder::authorize(responderDecision($reason));

        return 'ok';
    });
    $response = $this->getJson('/responder');

    expect(DecisionResponder::status(responderDecision($reason)))->toBe($status)
        ->and($response->status())->toBe($status ?? 200)
        ->and($response->getContent())->not->toContain('Secret', 'RuntimeException', 'source_error', 'policy_error');

    if ($status === 503) {
        expect($response->headers->get('Retry-After'))->toBe('5');
    }
})->with([
    'allow' => [DecisionReason::Granted, null],
    'deny' => [DecisionReason::NotGranted, 403],
    'transient failure' => [DecisionReason::SourceError, 503],
    'consistency failure' => [DecisionReason::ConsistencyError, 503],
    'contract failure' => [DecisionReason::PolicyError, 500],
]);

it('keeps the reason on the exception for logs only', function (): void {
    $error = DecisionFailedException::of(responderDecision(DecisionReason::HookError));

    expect($error?->reason)->toBe(DecisionReason::HookError)->and($error?->getStatusCode())->toBe(500)
        ->and($error?->getMessage())->toBe('Authorization could not be decided.')
        ->and(DecisionFailedException::of(responderDecision(DecisionReason::NotGranted)))->toBeNull();
});
