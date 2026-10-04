<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Authorization\GrantCondition;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\PassRestriction;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;

it('before deny stops the sources and observes the denial afterwards', function (): void {
    $source = new GeneratedSource(direct: [AuthorizationWorld::grant()]);
    $seen = null;
    [$engine,$panel,$request] = AuthorizationWorld::compile($source, function (PanelBuilder $panel) use (&$seen): void {
        $panel->before(fn (): BeforeResult => BeforeResult::Deny)->after(function (AccessRequest $r, Decision $d) use (&$seen): void {
            $seen = $d;
        });
    });
    $decision = $engine->decide($panel, $request);
    expect($decision->reason)->toBe(DecisionReason::Hook)->and($seen)->toBe($decision)->and($source->grantReads)->toBe(0);
});
it('before invalid return or exception is HookError', function (string $kind): void {
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), fn (PanelBuilder $panel) => $panel->before($kind === 'return' ? fn (): bool => true : fn () => throw new RuntimeException('before')));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::HookError);
})->with(['return', 'exception']);
it('policy exception or invalid return is PolicyError', function (string $kind): void {
    RuntimePolicy::$callback = $kind === 'return' ? fn (): string => 'yes' : fn () => throw new RuntimeException('policy');
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource);
    $request = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy'));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::PolicyError);
})->with(['return', 'exception']);
it('condition exceptions deny even a superadmin', function (): void {
    $condition = new class implements GrantCondition
    {
        public function allows(Grant|RoleContribution $grant, AccessRequest $request, EvaluationContext $context): bool
        {
            throw new RuntimeException('condition');
        }
    };
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), fn (PanelBuilder $panel) => $panel->grantConditions([$condition]));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::ConditionError);
});
it('restriction errors in applies check or key deny', function (string $step): void {
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), fn (PanelBuilder $panel) => $panel->restrictions([new RecordingRestriction(error: $step)]));
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::RestrictionError);
})->with(['applies', 'check', 'key']);
it('contains DefinitionException thrown by restriction code and still observes the denial', function (string $step): void {
    $restriction = new class extends PassRestriction
    {
        public function key(): string
        {
            throw new DefinitionException('user restriction failure');
        }
    };

    if ($step === 'factory') {
        app()->bind(PassRestriction::class, fn () => throw new DefinitionException('user factory failure'));
    }
    $seen = null;
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), function (PanelBuilder $panel) use ($step, $restriction, &$seen): void {
        $panel->restrictions([$step === 'factory' ? PassRestriction::class : $restriction]);
        $panel->after(function (Decision $decision) use (&$seen): void {
            $seen = $decision;
        });
    });
    $decision = $engine->decide($panel, $request);
    expect($decision->reason)->toBe(DecisionReason::RestrictionError)->and($seen)->toBe($decision);
})->with(['key', 'factory']);
it('after errors do not change allow and later observers still run', function (): void {
    $seen = null;
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), function (PanelBuilder $panel) use (&$seen): void {
        $panel->after([fn () => throw new RuntimeException('after'), function (AccessRequest $r, EvaluationContext $c, Decision $d) use (&$seen): void {
            $seen = $d;
        }]);
    });
    $decision = $engine->decide($panel, $request);
    expect($decision->allowed())->toBeTrue()->and($seen)->toBe($decision);
});

it('handles a lazy iterator throwing after its first grant as SourceError', function (): void {
    $source = new GeneratedSource(read: function (): iterable {
        yield AuthorizationWorld::grant();

        throw new RuntimeException('lazy source');
    });
    [$engine,$panel,$request] = AuthorizationWorld::compile($source);
    expect($engine->decide($panel, $request)->reason)->toBe(DecisionReason::SourceError);
});
it('restrictions skip only inapplicable entries and otherwise keep registration order', function (): void {
    $skipped = new RecordingRestriction(name: 'skip', deny: true, applicable: false);
    $deny = new RecordingRestriction(name: 'stop', deny: true);
    $last = new RecordingRestriction(name: 'last');
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), fn (PanelBuilder $panel) => $panel->restrictions([$skipped, $deny, $last]));
    expect($engine->decide($panel, $request)->component)->toBe('stop')->and($skipped->checks)->toBe(0)->and($deny->checks)->toBe(1)->and($last->checks)->toBe(0);
});
