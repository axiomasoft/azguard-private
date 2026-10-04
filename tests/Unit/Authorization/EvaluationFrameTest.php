<?php

declare(strict_types=1);

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Policies\RuntimeInvoker;
use AzGuard\Tests\Fixtures\Panels\User;

function unitEvaluationFrame(): EvaluationFrame
{
    $panel = (new PanelCompiler)->compile(new PanelRecipe('alpha'));

    return new EvaluationFrame($panel, AccessScope::in(TenantRef::global()), CodeStateToken::of('alpha', 'build', 'fingerprint'), new DateTimeImmutable('2026-10-05T00:00:00+00:00'), ActorRef::of('user', 1));
}
it('copies branch inputs without changing the base frame or decision time', function (): void {
    $frame = unitEvaluationFrame();
    $grant = Grant::of(PermissionPattern::of('alpha', 'orders.view'), 'fixed', $frame->scope());
    $branch = $frame->forContribution($grant);
    expect($frame->grant())->toBeNull()->and($branch->grant())->toBe($grant)->and($branch->now())->toBe($frame->now())
        ->and($branch->state())->toBe($frame->state())->and($branch->scopes())->toBe([$frame->scope()->context]);
    $qualified = $frame->withAuthority([$grant], true);
    expect($qualified->matchingGrants())->toBe([$grant])->and($qualified->qualifiedSuperAdmin)->toBeTrue()->and($frame->qualifiedSuperAdmin)->toBeFalse();
});
it('binds same-class policy user and resource by their positions rather than class DI', function (): void {
    $user = new User;
    $user->setAttribute('id', 1);
    $resource = new User;
    $resource->setAttribute('id', 2);
    $result = app(RuntimeInvoker::class)->invoke(fn (User $who, User $target): array => [$who->getKey(), $target->getKey()], ['user' => $user, 'resource' => $resource]);
    expect($result)->toBe([1, 2]);
});
it('keeps native policy positions when parameter names collide with reserved slots', function (string $signature): void {
    $user = new User;
    $user->setAttribute('id', 1);
    $resource = new User;
    $resource->setAttribute('id', 2);
    $callback = match ($signature) {
        'second named user' => fn (User $agent, User $user): array => [$agent->getKey(), $user->getKey()],
        'first named resource' => fn (User $resource, User $other): array => [$resource->getKey(), $other->getKey()],
        'both names reversed' => fn (User $resource, User $user): array => [$resource->getKey(), $user->getKey()],
    };
    expect(app(RuntimeInvoker::class)->invoke($callback, ['user' => $user, 'resource' => $resource]))->toBe([1, 2]);
})->with(['second named user', 'first named resource', 'both names reversed']);
it('never invents a typed model for a missing reserved resource', function (): void {
    $user = new User;
    expect(fn () => app(RuntimeInvoker::class)->invoke(fn (User $who, User $target): bool => true, ['user' => $user, 'resource' => null]))->toThrow(RuntimeException::class, 'resource');
});
it('injects unrelated services and preserves optional defaults and nullable inputs', function (): void {
    $service = new stdClass;
    app()->instance(stdClass::class, $service);
    expect(app(RuntimeInvoker::class)->invoke(fn (?User $who, stdClass $service, string $label = 'default'): array => [$who, $service, $label], ['user' => null, 'resource' => null]))->toBe([null, $service, 'default']);
});
it('injects a nullable service after native policy user and resource slots', function (): void {
    $service = new stdClass;
    app()->instance(stdClass::class, $service);
    expect(app(RuntimeInvoker::class)->invoke(fn (?User $who, ?User $record, ?stdClass $service): mixed => $service, ['user' => null, 'resource' => null]))->toBe($service);
});
it('does not pass an unused resource to a one-user policy', function (): void {
    $user = new User;
    expect(app(RuntimeInvoker::class)->invoke(fn (User $who): int => func_num_args(), ['user' => $user, 'resource' => new User]))->toBe(1);
});
it('supports a policy resource variadic without changing its identity', function (): void {
    $user = new User;
    $resource = new User;
    expect(app(RuntimeInvoker::class)->invoke(fn (User $who, User ...$records): array => $records, ['user' => $user, 'resource' => $resource]))->toBe([$resource]);
});
it('binds hook aliases by runtime type and rejects ambiguous matches', function (): void {
    $frame = unitEvaluationFrame();
    $request = AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('alpha', 'orders.view'));
    expect(app(RuntimeInvoker::class)->invoke(fn ($r, EvaluationContext $c): array => [$r, $c], ['request' => $request, 'context' => $frame]))->toBe([$request, $frame]);
    expect(fn () => app(RuntimeInvoker::class)->invoke(fn (object $ambiguous) => true, ['request' => $request, 'context' => $frame]))->toThrow(RuntimeException::class, 'Ambiguous');
});
