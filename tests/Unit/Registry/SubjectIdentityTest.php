<?php

declare(strict_types=1);

use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Registry\Resolver\SubjectIdentity;
use AzGuard\Tests\Stubs\User;
use Illuminate\Database\Eloquent\Relations\Relation;

it('normalizes integer and string ids equally', function (): void {
    $fromInt = SubjectIdentity::fromPersisted('user', 1);
    $fromString = SubjectIdentity::fromPersisted('user', '1');

    expect($fromInt->equals($fromString))->toBeTrue()
        ->and($fromInt->digest())->toBe($fromString->digest());
});

it('does not rewrite leading-zero string ids', function (): void {
    $one = SubjectIdentity::fromPersisted('user', '1');
    $zeroOne = SubjectIdentity::fromPersisted('user', '01');

    expect($one->digest())->not->toBe($zeroOne->digest());
});

it('uses morph alias from an enforced morph map on authenticatables', function (): void {
    Relation::enforceMorphMap(['alias-user' => User::class]);

    $user = User::make();
    $user->forceFill(['id' => 1]);

    expect(SubjectIdentity::fromAuthenticatable($user)->morphType)->toBe('alias-user');

    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

it('produces deterministic portable digests under 64-byte keys', function (): void {
    $subject = SubjectIdentity::fromPersisted('panel|ctx/unicode', 'uuid-123');
    $cache = new PermissionCache;

    $permKey = $cache->keyFor($subject, 'app|panel', 'ctx/disc');
    $epochKey = $cache->epochStorageKey($subject, 'app|panel');

    expect($permKey)->toMatch('/^azg:v2:perm:[A-Za-z0-9_-]+$/')
        ->and(strlen($permKey))->toBeLessThanOrEqual(64)
        ->and($epochKey)->toMatch('/^azg:v2:epoch:[A-Za-z0-9_-]+$/')
        ->and(strlen($epochKey))->toBeLessThanOrEqual(64)
        ->and($cache->keyFor($subject, 'app|panel', 'ctx/disc'))->toBe($permKey);
});
