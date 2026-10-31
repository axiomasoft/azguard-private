<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;

it('observes bounded failure dimensions without identities or grant fields', function (): void {
    $samples = [];
    $source = new GeneratedSource(read: static function (): iterable {
        throw new RuntimeException('secret subject tenant grant fields');
        yield;
    });
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, function (PanelBuilder $panel) use (&$samples): void {
        $panel->after(function (AccessRequest $request, EvaluationContext $context, Decision $decision) use (&$samples): void {
            $sources = [GeneratedSource::class => 'database'];
            $samples[] = [
                'outcome' => $decision->failure()?->value ?? ($decision->allowed() ? 'allow' : 'deny'),
                'reason' => $decision->reason->value,
                'source' => $sources[$decision->component ?? ''] ?? 'other',
            ];
        });
    });
    $decision = $engine->decide($panel, $request);
    expect($decision->reason)->toBe(DecisionReason::SourceError)
        ->and($samples)->toBe([['outcome' => 'transient', 'reason' => 'source_error', 'source' => 'database']]);
});

it('preserves an ordinary denial or primary failure when telemetry throws', function (bool $failure): void {
    $source = $failure ? new GeneratedSource(read: static function (): iterable {
        throw new RuntimeException('source failure');
        yield;
    }) : new GeneratedSource;
    $seen = null;
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, function (PanelBuilder $panel) use (&$seen): void {
        $panel->after([
            static function (AccessRequest $request, EvaluationContext $context, Decision $decision): void {
                throw new RuntimeException('telemetry failure');
            },
            function (AccessRequest $request, EvaluationContext $context, Decision $decision) use (&$seen): void {
                $seen = $decision;
            },
        ]);
    });
    $decision = $engine->decide($panel, $request);
    expect($decision->allowed())->toBeFalse()
        ->and($decision->reason)->toBe($failure ? DecisionReason::SourceError : DecisionReason::NotGranted)
        ->and($seen)->toBe($decision)
        ->and($decision->failure()?->value)->toBe($failure ? 'transient' : null);
})->with([false, true]);

it('separates grants, ordinary denials and contract failures in bounded samples', function (string $case): void {
    $samples = [];
    $source = new GeneratedSource(direct: $case === 'allow' ? [AuthorizationWorld::grant(['secret' => 'grant-field-secret'])] : []);
    [$engine, $panel, $request] = AuthorizationWorld::compile($source, function (PanelBuilder $panel) use (&$samples): void {
        $panel->after(function (AccessRequest $request, EvaluationContext $context, Decision $decision) use (&$samples): void {
            $samples[] = [
                'outcome' => $decision->failure()?->value ?? ($decision->allowed() ? 'allow' : 'deny'),
                'reason' => $decision->reason->value,
                'source' => [GeneratedSource::class => 'database'][$decision->component ?? ''] ?? 'other',
            ];
        });
    });

    if ($case === 'contract') {
        RuntimePolicy::$callback = static fn () => throw new RuntimeException('private policy data');
        $request = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy'));
    }
    $decision = $engine->decide($panel, $request);
    expect($samples)->toHaveCount(1)
        ->and($samples[0]['outcome'])->toBe($case)
        ->and($samples[0]['reason'])->toBe($decision->reason->value)
        ->and(json_encode($samples))->not->toContain('grant-field-secret', 'private policy data');
})->with(['allow', 'deny', 'contract']);
