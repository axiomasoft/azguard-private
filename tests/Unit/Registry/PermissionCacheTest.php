<?php

declare(strict_types=1);

use AzGuard\Registry\Resolver\PermissionCache;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Tests\Stubs\User;
use Carbon\CarbonImmutable;

describe('PermissionCache', function () {

    afterEach(function () {
        Carbon\Carbon::setTestNow();
    });

    it('generates v2 cache keys embedding the current epoch digest', function () {
        $cache = new PermissionCache;
        $subject = permissionCacheTestSubject(42);

        $key = $cache->keyFor($subject, 'app');

        expect($key)->toStartWith('azg:v2:perm:')
            ->and(strlen($key))->toBeLessThanOrEqual(64)
            ->and($cache->keyFor($subject, 'app'))->toBe($key);
    });

    it('uses a fixed internal v2 namespace — az-guard.cache.key is not a knob (F38)', function () {
        $cache = new PermissionCache;
        $subject = permissionCacheTestSubject(42);

        config(['az-guard.cache.key' => 'tenant7.acl']);

        $base = $cache->keyFor($subject, 'app');
        $withDisc = $cache->keyFor($subject, 'app', 'ctx-9');

        expect($withDisc)->not->toBe($base)
            ->and($base)->toStartWith('azg:v2:perm:');
    });

    it('remembers result for same subject+panel', function () {
        $cache = new PermissionCache;
        $subject = permissionCacheTestSubject(1);
        $calls = 0;

        $cache->rememberForRequest($subject, 'app', function () use (&$calls): PermissionSet {
            $calls++;

            return PermissionSet::fromKeys(['app.posts.view']);
        });

        $set2 = $cache->rememberForRequest($subject, 'app', function () use (&$calls): PermissionSet {
            $calls++;

            return PermissionSet::fromKeys(['different']);
        });

        expect($calls)->toBe(1)
            ->and($set2->keys())->toBe(['app.posts.view']);
    });

    it('stores separate entries for different subject identities', function () {
        $cache = new PermissionCache;

        $setA = $cache->rememberForRequest(
            permissionCacheTestSubject(1, User::class),
            'app',
            fn () => PermissionSet::fromKeys(['app.posts.view']),
        );
        $setB = $cache->rememberForRequest(
            permissionCacheTestSubject(2, User::class),
            'app',
            fn () => PermissionSet::fromKeys(['app.tags.view']),
        );

        expect($setA->keys())->toBe(['app.posts.view'])
            ->and($setB->keys())->toBe(['app.tags.view']);
    });

    it('does not collide when two morph types share the same numeric id', function () {
        config()->set('cache.stores.azguard_test', ['driver' => 'array']);
        config()->set('az-guard.cache.store', 'azguard_test');

        $cache = new PermissionCache;
        $userCalls = 0;
        $adminCalls = 0;

        $userSubject = permissionCacheTestSubject(1, User::class);
        $adminSubject = permissionCacheTestSubject(1, 'AzGuard\\Tests\\Stubs\\AdminActor');

        $userSet = $cache->rememberForRequest($userSubject, 'app', function () use (&$userCalls): PermissionSet {
            $userCalls++;

            return PermissionSet::fromKeys(['app.posts.view']);
        });

        $adminSet = $cache->rememberForRequest($adminSubject, 'app', function () use (&$adminCalls): PermissionSet {
            $adminCalls++;

            return PermissionSet::fromKeys([]);
        });

        expect($userCalls)->toBe(1)
            ->and($adminCalls)->toBe(1)
            ->and($userSet->keys())->toBe(['app.posts.view'])
            ->and($adminSet->keys())->toBe([])
            ->and($cache->keyFor($userSubject, 'app'))->not->toBe($cache->keyFor($adminSubject, 'app'));

        $cache->forgetForUser($userSubject, 'app');

        $cache->rememberForRequest($userSubject, 'app', function () use (&$userCalls): PermissionSet {
            $userCalls++;

            return PermissionSet::fromKeys(['app.posts.view']);
        });

        $cache->rememberForRequest($adminSubject, 'app', function () use (&$adminCalls): PermissionSet {
            $adminCalls++;

            return PermissionSet::fromKeys([]);
        });

        expect($userCalls)->toBe(2)
            ->and($adminCalls)->toBe(1);
    });

    it('forgetAll clears entire request cache', function () {
        $cache = new PermissionCache;
        $subject = permissionCacheTestSubject(1);
        $calls = 0;

        $cache->rememberForRequest($subject, 'app', function () use (&$calls): PermissionSet {
            $calls++;

            return PermissionSet::fromKeys(['app.posts.view']);
        });

        $cache->forgetAll();

        $cache->rememberForRequest($subject, 'app', function () use (&$calls): PermissionSet {
            $calls++;

            return PermissionSet::fromKeys(['app.posts.view']);
        });

        expect($calls)->toBe(2);
    });

    it('forgetRequestCache drops the in-process entry WITHOUT bumping the epoch', function () {
        config()->set('cache.stores.azguard_test', ['driver' => 'array']);
        config()->set('az-guard.cache.store', 'azguard_test');

        $cache = new PermissionCache;
        $subject = permissionCacheTestSubject(1);
        $calls = 0;

        $cache->rememberForRequest($subject, 'app', function () use (&$calls): PermissionSet {
            $calls++;

            return PermissionSet::fromKeys(['app.posts.view']);
        });

        $before = $cache->keyFor($subject, 'app');

        $cache->forgetRequestCache($subject, 'app');

        expect($cache->keyFor($subject, 'app'))->toBe($before);
    });

    it('forgetRequestCache forces an in-process recompute of that subject+panel', function () {
        $cache = new PermissionCache;
        $subject = permissionCacheTestSubject(1);
        $calls = 0;

        $cache->rememberForRequest($subject, 'app', function () use (&$calls): PermissionSet {
            $calls++;

            return PermissionSet::fromKeys(['app.posts.view']);
        });

        $cache->forgetRequestCache($subject, 'app');

        $cache->rememberForRequest($subject, 'app', function () use (&$calls): PermissionSet {
            $calls++;

            return PermissionSet::fromKeys(['app.posts.view']);
        });

        expect($calls)->toBe(2);
    });

    it('forgetForUser removes only that subject+panel entry', function () {
        $cache = new PermissionCache;
        $subjectOne = permissionCacheTestSubject(1);
        $subjectTwo = permissionCacheTestSubject(2);
        $calls = 0;

        $cache->rememberForRequest($subjectOne, 'app', fn () => PermissionSet::fromKeys(['app.posts.view']));
        $cache->rememberForRequest($subjectOne, 'admin', fn () => PermissionSet::fromKeys(['admin.users.view']));
        $cache->rememberForRequest($subjectTwo, 'app', fn () => PermissionSet::fromKeys(['app.tags.view']));

        $cache->forgetForUser($subjectOne, 'app');

        $cache->rememberForRequest($subjectOne, 'app', function () use (&$calls): PermissionSet {
            $calls++;

            return PermissionSet::fromKeys(['app.posts.view']);
        });

        $cache->rememberForRequest($subjectOne, 'admin', function () use (&$calls): PermissionSet {
            $calls++;

            return PermissionSet::fromKeys(['admin.users.view']);
        });

        expect($calls)->toBe(1);
    });

    it('request cache allows until the exact deadline then recomputes without prune', function () {
        $cache = new PermissionCache;
        $subject = permissionCacheTestSubject(1);
        $deadline = CarbonImmutable::parse('2026-09-23T12:00:00.000000Z');
        $calls = 0;

        $resolve = function () use ($deadline, &$calls): PermissionSet {
            $calls++;

            if (! now()->lt($deadline)) {
                return PermissionSet::empty();
            }

            return PermissionSet::fromKeys(['app.invoice.view'])->withValidUntil($deadline);
        };

        Carbon\Carbon::setTestNow($deadline->subMicrosecond());
        expect($cache->rememberForRequest($subject, 'app', $resolve)->grants('app.invoice.view'))->toBeTrue()
            ->and($calls)->toBe(1);

        Carbon\Carbon::setTestNow($deadline);
        expect($cache->rememberForRequest($subject, 'app', $resolve)->grants('app.invoice.view'))->toBeFalse()
            ->and($calls)->toBe(2);
    });

    it('durable envelope miss at exact deadline on a fresh cache instance', function () {
        config()->set('cache.stores.azguard_test', ['driver' => 'array']);
        config()->set('az-guard.cache.store', 'azguard_test');

        $deadline = CarbonImmutable::parse('2026-09-23T12:00:00.000000Z');
        $subject = permissionCacheTestSubject(1);
        $calls = 0;
        $resolve = function () use ($deadline, &$calls): PermissionSet {
            $calls++;

            if (! now()->lt($deadline)) {
                return PermissionSet::empty();
            }

            return PermissionSet::fromKeys(['app.invoice.view'])->withValidUntil($deadline);
        };

        Carbon\Carbon::setTestNow($deadline->subSecond());
        (new PermissionCache)->rememberForRequest($subject, 'app', $resolve);

        Carbon\Carbon::setTestNow($deadline);
        $fresh = new PermissionCache;
        expect($fresh->rememberForRequest($subject, 'app', $resolve)->grants('app.invoice.view'))->toBeFalse()
            ->and($calls)->toBe(2);
    });

    it('recompute after the earlier contributor expires keeps a later allow', function () {
        $cache = new PermissionCache;
        $subject = permissionCacheTestSubject(1);
        $early = CarbonImmutable::parse('2026-09-23T12:00:00.000000Z');
        $late = $early->addHour();
        $calls = 0;

        $resolve = function () use ($early, $late, &$calls): PermissionSet {
            $calls++;
            $set = PermissionSet::empty();

            if (now()->lt($late)) {
                $set = $set->merge(PermissionSet::fromKeys(['app.invoice.view'])->withValidUntil($late));
            }

            if (now()->lt($early)) {
                $set = $set->merge(PermissionSet::fromKeys(['app.invoice.view'])->withValidUntil($early));
            }

            return $set;
        };

        Carbon\Carbon::setTestNow($early->subSecond());
        expect($cache->rememberForRequest($subject, 'app', $resolve)->grants('app.invoice.view'))->toBeTrue();

        Carbon\Carbon::setTestNow($early);
        expect($cache->rememberForRequest($subject, 'app', $resolve)->grants('app.invoice.view'))->toBeTrue()
            ->and($calls)->toBe(2);
    });

    it('raw v1 arrays and malformed envelopes are misses, not stale allows', function () {
        config()->set('cache.stores.azguard_test', ['driver' => 'array']);
        config()->set('az-guard.cache.store', 'azguard_test');

        $cache = new PermissionCache;
        $subject = permissionCacheTestSubject(9);
        $key = $cache->keyFor($subject, 'app');
        $store = cache()->store('azguard_test');
        $calls = 0;
        $fresh = PermissionSet::fromKeys(['app.fresh.view']);

        foreach ([
            ['app.stale.view'],
            ['version' => 1, 'keys' => ['app.stale.view'], 'valid_until' => null],
            ['version' => 2, 'keys' => 'app.stale.view', 'valid_until' => null],
            ['version' => 2, 'keys' => ['app.stale.view']],
            ['version' => 2, 'keys' => ['app.stale.view'], 'valid_until' => 'not-a-time'],
            ['version' => 2, 'keys' => ['app.stale.view'], 'valid_until' => '2020-01-01T00:00:00.000000Z'],
        ] as $payload) {
            $store->put($key, $payload);
            $calls = 0;
            $set = $cache->rememberForRequest($subject, 'app', function () use (&$calls, $fresh): PermissionSet {
                $calls++;

                return $fresh;
            });

            expect($set->keys())->toBe(['app.fresh.view'])
                ->and($calls)->toBe(1);

            $cache->forgetAll();
        }
    });

    it('does not cache an already-expired resolve result', function () {
        $cache = new PermissionCache;
        $subject = permissionCacheTestSubject(3);
        $deadline = CarbonImmutable::parse('2026-09-23T12:00:00.000000Z');
        $calls = 0;

        Carbon\Carbon::setTestNow($deadline);
        $cache->rememberForRequest($subject, 'app', function () use ($deadline, &$calls): PermissionSet {
            $calls++;

            return PermissionSet::fromKeys(['app.invoice.view'])->withValidUntil($deadline);
        });

        $cache->rememberForRequest($subject, 'app', function () use ($deadline, &$calls): PermissionSet {
            $calls++;

            return PermissionSet::fromKeys(['app.invoice.view'])->withValidUntil($deadline);
        });

        expect($calls)->toBe(2);
    });
});
