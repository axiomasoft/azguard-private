<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidAssignmentScopeException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Unit\Kernel\Identity\IdentityGenerator;

it('keeps an assignment scope key unambiguous for random type and id', function (): void {
    IdentityGenerator::seed();
    $seen = [];

    for ($i = 0; $i < 10_000; $i++) {
        $type = IdentityGenerator::alias();
        $id = IdentityGenerator::id();
        $ref = AssignmentScopeRef::of($type, $id);
        $pair = [$type, (string) $id];

        expect($seen[$ref->key()] ?? $pair)->toBe($pair)
            ->and(explode(':', $ref->key(), 2))->toBe($pair)
            ->and(AssignmentScopeRef::of($type, (string) $id)->equals($ref))->toBeTrue()
            ->and(IdentityCodec::decode(IdentityCodec::encode($ref))->equals($ref))->toBeTrue();

        $seen[$ref->key()] = $pair;
    }
});

it('canonicalizes an int id and keeps a string id byte for byte', function (): void {
    expect(AssignmentScopeRef::of('w', 7)->equals(AssignmentScopeRef::of('w', '7')))->toBeTrue()
        ->and(AssignmentScopeRef::of('w', '007')->equals(AssignmentScopeRef::of('w', 7)))->toBeFalse()
        ->and(SubjectRef::of('user', -5)->id())->toBe('-5')
        ->and(SubjectRef::of('user', str_repeat('x', 64))->id())->toHaveLength(64);
});

it('rejects an invalid alias or id with the reference exception', function (callable $make, string $exception): void {
    expect($make)->toThrow($exception);
})->with([
    'context alias with colon' => [fn () => AssignmentScopeRef::of('workspace:a', 7), InvalidAssignmentScopeException::class],
    'context empty id' => [fn () => AssignmentScopeRef::of('w', ''), InvalidAssignmentScopeException::class],
    'subject alias uppercase' => [fn () => SubjectRef::of('User', 1), InvalidIdentityException::class],
    'subject alias leading dot' => [fn () => SubjectRef::of('.user', 1), InvalidIdentityException::class],
    'subject alias of 129' => [fn () => SubjectRef::of(str_repeat('a', 129), 1), InvalidIdentityException::class],
    'subject id of 65' => [fn () => SubjectRef::of('user', str_repeat('x', 65)), InvalidIdentityException::class],
    'subject id with space' => [fn () => SubjectRef::of('user', 'a b'), InvalidIdentityException::class],
    'subject id with tab' => [fn () => SubjectRef::of('user', "a\tb"), InvalidIdentityException::class],
    'subject id non-ascii' => [fn () => SubjectRef::of('user', 'é'), InvalidIdentityException::class],
    'tenant id with newline' => [fn () => TenantRef::of('org', "1\n"), InvalidIdentityException::class],
    'actor reserved type' => [fn () => ActorRef::of(ActorRef::SYSTEM_TYPE, 1), InvalidIdentityException::class],
    'actor id with control byte' => [fn () => ActorRef::of('user', "1\0"), InvalidIdentityException::class],
]);

it('accepts the longest alias', function (): void {
    expect(SubjectRef::of(str_repeat('a', 128), 1)->type())->toHaveLength(128);
});

it('keeps the global form apart from an ordinary reference named global', function (string $class): void {
    $global = $class::global();
    $named = $class::of('global', 1);

    expect($global->isGlobal())->toBeTrue()
        ->and($global->key())->toBe('global')
        ->and($global->type())->toBeNull()
        ->and($global->id())->toBeNull()
        ->and($named->isGlobal())->toBeFalse()
        ->and($named->key())->toBe('global:1')
        ->and($named->equals($global))->toBeFalse()
        ->and(IdentityCodec::encode($named))->not->toBe(IdentityCodec::encode($global));
})->with([TenantRef::class, AssignmentScopeRef::class]);

it('separates tenants and the global context inside a tenant from the global tenant', function (): void {
    $project = AssignmentScopeRef::of('project', 7);

    expect(AccessScope::in(TenantRef::of('org', 1), $project)->equals(AccessScope::in(TenantRef::of('org', 2), $project)))->toBeFalse()
        ->and(AccessScope::in(TenantRef::of('org', 1))->equals(AccessScope::in(TenantRef::global())))->toBeFalse()
        ->and(AccessScope::in(TenantRef::of('org', 1))->context->isGlobal())->toBeTrue()
        ->and(AccessScope::in(TenantRef::of('org', 1))->equals(AccessScope::in(TenantRef::of('org', 1), AssignmentScopeRef::global())))->toBeTrue()
        ->and(IdentityCodec::compose([AccessScope::in(TenantRef::of('org', 1), $project)]))
        ->not->toBe(IdentityCodec::compose([AccessScope::in(TenantRef::of('org', 2), $project)]));
});

it('distinguishes subject, tenant and context with the same alias and id', function (): void {
    $refs = [SubjectRef::of('x', 7), TenantRef::of('x', 7), AssignmentScopeRef::of('x', 7)];

    $encoded = array_map(IdentityCodec::encode(...), $refs);
    $composed = array_map(static fn (object $ref): string => IdentityCodec::compose([$ref]), $refs);

    expect(array_unique(array_map('serialize', $encoded)))->toHaveCount(3)
        ->and(array_unique($composed))->toHaveCount(3)
        ->and(array_unique(array_map(static fn (object $ref): string => $ref->key(), $refs)))->toHaveCount(1);
});

it('describes the system actor without an id', function (): void {
    $system = ActorRef::system('scheduled cleanup');
    $user = ActorRef::of('user', 42, 'manual edit');

    expect($system->type)->toBe('azguard.system')
        ->and($system->id)->toBeNull()
        ->and($system->reason)->toBe('scheduled cleanup')
        ->and(ActorRef::system()->reason)->toBeNull()
        ->and($user->type)->toBe('user')
        ->and($user->id)->toBe('42')
        ->and($user->reason)->toBe('manual edit');
});

it('offers every assignment scope only as a value of its own', function (): void {
    expect(AnyAssignmentScope::all())->toEqual(AnyAssignmentScope::all())
        ->and(fn () => IdentityCodec::encode(AnyAssignmentScope::all()))->toThrow(InvalidIdentityException::class);
});
