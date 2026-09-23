<?php

declare(strict_types=1);

use AzGuard\Facades\AzGuard;
use AzGuard\Registry\Contracts\GrantSource;
use AzGuard\Registry\Values\PermissionSet;
use AzGuard\Tests\Stubs\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

// A custom source that grants a real catalog key on the 'test' panel.
class StubCustomGrantSource implements GrantSource
{
    public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
    {
        return $panelId === 'test'
            ? PermissionSet::fromKeys(['test.post.view'])
            : PermissionSet::empty();
    }

    public function priority(): int
    {
        return 50;
    }
}

/**
 * H4: AzGuard::registerGrantSource() plugs a custom source into the resolution
 * chain without touching the service provider.
 */
it('resolves permissions from a registered custom grant source', function () {
    AzGuard::registerGrantSource(StubCustomGrantSource::class);

    // Rebuild the scoped resolver so it re-reads the freshly tagged source.
    app()->forgetScopedInstances();

    $user = User::factory()->create(); // no roles at all

    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue()
        ->and($user->hasPermission('test.post.delete', 'test'))->toBeFalse();
});

class StubExpiringGrantSource implements GrantSource
{
    public static ?DateTimeInterface $deadline = null;

    public function permissionsFor(Authenticatable $user, string $panelId): PermissionSet
    {
        $deadline = self::$deadline;

        if (! $deadline instanceof DateTimeInterface || ! now()->lt($deadline)) {
            return PermissionSet::empty();
        }

        return PermissionSet::fromKeys(['test.post.view'])->withValidUntil($deadline);
    }

    public function priority(): int
    {
        return 50;
    }
}

it('custom source without deadline stays compatible', function () {
    AzGuard::registerGrantSource(StubCustomGrantSource::class);
    app()->forgetScopedInstances();

    $legacy = User::factory()->create();
    expect($legacy->hasPermission('test.post.view', 'test'))->toBeTrue();
});

it('custom source that sets validUntil denies at the exact deadline', function () {
    $deadline = CarbonImmutable::parse('2026-09-23T12:00:00.000000Z');
    StubExpiringGrantSource::$deadline = $deadline;
    AzGuard::registerGrantSource(StubExpiringGrantSource::class);
    app()->forgetScopedInstances();

    $user = User::factory()->create();
    Carbon\Carbon::setTestNow($deadline->subSecond());
    expect($user->hasPermission('test.post.view', 'test'))->toBeTrue();

    Carbon\Carbon::setTestNow($deadline);
    $user->flushPermissions();
    expect($user->hasPermission('test.post.view', 'test'))->toBeFalse();

    StubExpiringGrantSource::$deadline = null;
    Carbon\Carbon::setTestNow();
});
