<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\Cache\PermissionSetCache;
use AzGuard\AzGuardManager;
use AzGuard\Catalog\CatalogCache;
use AzGuard\Changes\ActingActor;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Scopes\WithinContext;
use AzGuard\Sources\SourceManager;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Scopes\ScopeSource;
use AzGuard\Tests\Fixtures\Scopes\ScopeWorld;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;

// The next Octane request or queue job flushes scoped instances; the authorizer must not keep reading the previous ones.
it('evaluates every entry point in the tenant of the current lifecycle', function (string $entry): void {
    [$engine, $panel, $request] = ScopeWorld::compile(new ScopeSource(direct: [ScopeWorld::grant(ScopeWorld::scope('A'))]));
    $evaluate = function () use ($entry, $panel, $request): array {
        $decision = match ($entry) {
            'decide' => app(Authorizer::class)->decide($panel, $request),
            'explain' => app(Authorizer::class)->explain($panel, $request)->decision(),
            'decideMany' => app(Authorizer::class)->decideMany([$request])->get(0),
            default => null,
        };

        return $decision === null
            ? [Gate::forUser(User::query()->findOrFail(1))->allows('admin:orders.view'), null]
            : [$decision->allowed(), $decision->scope->tenant->key()];
    };
    expect($engine)->toBe(app(Authorizer::class));

    app(CurrentContext::class)->set($panel, ScopeWorld::scope('A'));
    expect($evaluate()[0])->toBeTrue();

    app()->forgetScopedInstances();
    app(CurrentContext::class)->set($panel, ScopeWorld::scope('B'));
    [$allowed, $tenant] = $evaluate();

    expect($allowed)->toBeFalse()->and($tenant)->toBe($entry === 'gate' ? null : 'org:B');
})->with(['decide', 'explain', 'decideMany', 'gate']);

it('resolves the panel from the CurrentPanel of the current lifecycle', function (): void {
    [, , $registry] = PanelWorld::adminAndCabinet();
    app()->instance(PanelRegistry::class, $registry);
    app()->forgetInstance(Authorizer::class);
    $subject = SubjectRef::of('user', 1);

    app(CurrentPanel::class)->set($registry->get('admin'));
    [$first] = app(Authorizer::class)->resolve($subject, 'orders.view');

    app()->forgetScopedInstances();
    app(CurrentPanel::class)->set($registry->get('cabinet'));
    [$second] = app(Authorizer::class)->resolve($subject, 'orders.view');

    expect($first->id())->toBe('admin')->and($second->id())->toBe('cabinet');
});

it('keeps scoped services out of the singleton graph', function (): void {
    $scoped = [Authorizer::class, CurrentPanel::class, CurrentContext::class, WithinContext::class, PermissionSetCache::class, ActingActor::class];
    $reach = function (string $class, array &$seen) use (&$reach): void {
        if (isset($seen[$class]) || ! class_exists($class)) {
            return;
        }
        $seen[$class] = true;
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && ! in_array($type->getName(), [Application::class, Container::class], true)) {
                $reach($type->getName(), $seen);
            }
        }
    };
    $singletons = [AzGuardManager::class, StorageRegistry::class, CatalogCache::class, PanelRegistry::class, SourceManager::class];
    foreach ($singletons as $singleton) {
        $seen = [];
        $reach($singleton, $seen);
        expect(array_intersect($scoped, array_keys($seen)))->toBe([], $singleton);
    }
});
