<?php

declare(strict_types=1);

use AzGuard\Authorization\Authorizer;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\ScenarioGenerator;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;

uses()->group('batch');

it('uses PanelResolver for enum full name prefix and explicit panel', function (): void {
    [$resolver, , $registry] = PanelWorld::adminAndCabinet();
    app()->instance(PanelRegistry::class, $registry);
    app()->forgetInstance(Authorizer::class);
    $engine = app(Authorizer::class);
    $subject = User::query()->findOrFail(1);

    foreach ([$subject, SubjectRef::of('user', 1)] as $target) {
        foreach ([[OrderPermission::View, null], ['admin:orders.view', null], ['admin.orders.view', null], ['orders.view', 'admin']] as [$permission, $explicit]) {
            $expected = $resolver->resolve($target, $permission, $explicit);
            [$panel, $key] = $engine->resolve($target, $permission, $explicit);
            expect($panel)->toBe($expected['panel'])->and($key->equals($expected['key']))->toBeTrue();
        }
    }

    foreach ([OrderPermission::View, 'admin:orders.view', 'admin.orders.view'] as $conflict) {
        expect(fn () => $engine->resolve($subject, $conflict, 'cabinet'))->toThrow(ConflictingPanelException::class);
    }
});

it('rejects a scalar panel argument conflicting with the request before hooks or sources', function (): void {
    $s = new ScenarioGenerator(2);
    $source = new GeneratedSource;
    [$engine, $panel, $request] = $s->world([$source]);
    $foreign = AccessRequest::for($request->subject(), PermissionKey::of('beta', 'orders.view'));
    expect(fn () => $engine->decide($panel, $foreign))->toThrow(ConflictingPanelException::class)
        ->and($source->grantReads)->toBe(0)->and($source->roleReads)->toBe(0);
});

it('returns an empty ordered set without states for an empty batch', function (): void {
    $set = app(Authorizer::class)->decideMany([]);
    expect($set)->toHaveCount(0)->and($set->states())->toBe([])->and(iterator_to_array($set))->toBe([]);
});
