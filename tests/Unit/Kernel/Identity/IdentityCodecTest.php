<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidAssignmentScopeException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Unit\Kernel\Identity\IdentityGenerator;

it('encodes a reference as a tagged list and decodes it back', function (object $ref, array $encoded): void {
    expect(IdentityCodec::encode($ref))->toBe($encoded)
        ->and(IdentityCodec::decode($encoded))->toEqual($ref);
})->with([
    'permission' => [PermissionKey::of('admin', 'orders.view'), ['permission', 'admin', 'orders.view']],
    'pattern' => [PermissionPattern::of('admin', 'orders.view'), ['pattern', 'admin', 'orders.view']],
    'role' => [RoleKey::of('admin', 'manager'), ['role', 'admin', 'manager']],
    'subject' => [SubjectRef::of('user', 7), ['subject', 'user', '7']],
    'tenant' => [TenantRef::of('org', 1), ['tenant', 'org', '1']],
    'global tenant' => [TenantRef::global(), ['tenant', null, null]],
    'context' => [AssignmentScopeRef::of('project', 'a:7'), ['context', 'project', 'a:7']],
    'global context' => [AssignmentScopeRef::global(), ['context', null, null]],
    'scope' => [AccessScope::in(TenantRef::of('org', 1), AssignmentScopeRef::of('project', 7)), ['scope', 'org', '1', 'project', '7']],
    'tenant-wide scope' => [AccessScope::in(TenantRef::global()), ['scope', null, null, null, null]],
    'actor' => [ActorRef::of('user', 42, 'edit'), ['actor', 'user', '42', 'edit']],
    'actor without reason' => [ActorRef::of('user', 42), ['actor', 'user', '42', null]],
    'system actor' => [ActorRef::system('cleanup'), ['actor', 'azguard.system', null, 'cleanup']],
]);

it('rejects a malformed or invalid encoded reference', function (array $encoded, string $exception): void {
    expect(fn () => IdentityCodec::decode($encoded))->toThrow($exception);
})->with([
    'empty' => [[], InvalidIdentityException::class],
    'unknown kind' => [['ghost', 'a', 'b'], InvalidIdentityException::class],
    'subject without id' => [['subject', null, null], InvalidIdentityException::class],
    'permission without local' => [['permission', 'admin'], InvalidIdentityException::class],
    'int part' => [['subject', 'user', 7], InvalidIdentityException::class],
    'half global' => [['tenant', 'org', null], InvalidIdentityException::class],
    'scope too short' => [['scope', null, null, null], InvalidIdentityException::class],
    'scope with bad context' => [['scope', null, null, 'w:a', '7'], InvalidAssignmentScopeException::class],
    'actor without id' => [['actor', 'user', null, null], InvalidIdentityException::class],
    'actor with int reason' => [['actor', 'user', '1', 5], InvalidIdentityException::class],
    'system actor with id' => [['actor', 'azguard.system', '1', null], InvalidIdentityException::class],
    'context alias with colon' => [['context', 'w:a', '7'], InvalidAssignmentScopeException::class],
    'bare wildcard pattern' => [['pattern', 'admin', '**'], InvalidPermissionKeyException::class],
]);

it('leads a composite key with the codec version', function (): void {
    expect(IdentityCodec::VERSION)->toBe(1)
        ->and(IdentityCodec::compose([]))->toBe('[1]')
        ->and(IdentityCodec::compose(['admin', SubjectRef::of('user', 7), [AssignmentScopeRef::global()], null, true, 3]))
        ->toBe('[1,"admin",["subject","user","7"],[["context",null,null]],null,true,3]')
        ->and(IdentityCodec::digest(['admin']))->toBe(hash('sha256', '[1,"admin"]'));
});

it('keeps raw string boundaries in a composite key', function (): void {
    expect(IdentityCodec::digest(['a:b', 'c']))->not->toBe(IdentityCodec::digest(['a', 'b:c']))
        ->and(IdentityCodec::digest(['a', ['b']]))->not->toBe(IdentityCodec::digest([['a'], 'b']))
        ->and(IdentityCodec::digest([7]))->not->toBe(IdentityCodec::digest(['7']));
});

it('refuses a part that has no unambiguous encoding', function (array $parts): void {
    expect(fn () => IdentityCodec::compose($parts))->toThrow(InvalidIdentityException::class);
})->with([
    'float' => [[1.5]],
    'foreign object' => [[new stdClass]],
    'array with keys' => [[['a' => 1]]],
    'invalid utf-8' => [["\xff"]],
]);

it('validates a source label and exposes the default origin', function (): void {
    IdentityCodec::assertSourceLabel('relation:project');
    IdentityCodec::assertSourceLabel(IdentityCodec::DEFAULT_ORIGIN);
    IdentityCodec::assertSourceLabel(str_repeat('a', 128));

    expect(IdentityCodec::DEFAULT_ORIGIN)->toBe('manual')
        ->and(fn () => IdentityCodec::assertSourceLabel('Folder'))->toThrow(InvalidIdentityException::class)
        ->and(fn () => IdentityCodec::assertSourceLabel(':folder'))->toThrow(InvalidIdentityException::class)
        ->and(fn () => IdentityCodec::assertSourceLabel(str_repeat('a', 129)))->toThrow(InvalidIdentityException::class);
});

it('rejects recursive identity lists while allowing repeated references to a finite list', function (): void {
    $cycle = [];
    $cycle[] = &$cycle;

    expect(fn () => IdentityCodec::compose($cycle))->toThrow(InvalidIdentityException::class, 'recursive array');

    $shared = ['orders', 'view'];
    $parts = [&$shared, &$shared];

    expect(IdentityCodec::compose($parts))->toBe('[1,["orders","view"],["orders","view"]]');
});

it('gives distinct (panel, subject, contexts) tuples distinct digests', function (): void {
    IdentityGenerator::seed();
    $digests = [];
    $tuples = 0;
    $started = hrtime(true);

    // Half of the tuples come from a dense space whose ids embed `:context:x:`, so a codec that glues
    // parts without boundaries maps different context lists to the same key.
    $boundaryId = static fn (): string => implode(':', array_map(
        static fn (): string => ['a', 'context', 'x'][mt_rand(0, 2)],
        range(1, mt_rand(1, 3)),
    ));

    while ($tuples < 100_000) {
        $dense = mt_rand(0, 1) === 1;
        $panel = $dense ? 'admin' : ['admin', 'crm', 'a'][mt_rand(0, 2)];
        $subject = $dense ? ['u', 1] : [IdentityGenerator::alias(2), IdentityGenerator::id(3)];
        $contexts = [];

        for ($i = mt_rand(0, 3); $i > 0; $i--) {
            $contexts[] = match (true) {
                mt_rand(0, 9) === 0 => null,
                $dense => ['x', $boundaryId()],
                default => [IdentityGenerator::alias(2), IdentityGenerator::id(3)],
            };
        }

        $canonical = serialize([$panel, $subject[0], (string) $subject[1], array_map(
            static fn (?array $c): ?array => $c === null ? null : [$c[0], (string) $c[1]],
            $contexts,
        )]);

        $digest = IdentityCodec::digest([
            $panel,
            SubjectRef::of(...$subject),
            array_map(static fn (?array $c): AssignmentScopeRef => $c === null
                ? AssignmentScopeRef::global()
                : AssignmentScopeRef::of(...$c), $contexts),
        ]);

        if (! isset($digests[$canonical])) {
            $tuples++;
        }

        expect($digests[$canonical] ?? $digest)->toBe($digest);
        $digests[$canonical] = $digest;
    }

    expect(array_unique($digests))->toHaveCount(100_000);

    fwrite(STDERR, sprintf("\nV04: 100000 distinct tuples in %.2f s\n", (hrtime(true) - $started) / 1e9));
});
