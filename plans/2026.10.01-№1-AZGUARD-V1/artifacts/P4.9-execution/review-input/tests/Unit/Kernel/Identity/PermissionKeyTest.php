<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Exceptions\InvalidRoleKeyException;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Tests\Unit\Kernel\Identity\IdentityGenerator;

it('exposes the three forms of a permission key', function (): void {
    $key = PermissionKey::of('admin', 'orders.view');

    expect($key->panel())->toBe('admin')
        ->and($key->local())->toBe('orders.view')
        ->and($key->full())->toBe('admin:orders.view')
        ->and((string) $key)->toBe('admin:orders.view')
        ->and(json_encode([$key]))->toBe('["admin:orders.view"]')
        ->and($key->equals(PermissionKey::parse('admin:orders.view')))->toBeTrue()
        ->and($key->equals(PermissionKey::of('crm', 'orders.view')))->toBeFalse();
});

it('round-trips a random permission key and pattern through the full form', function (): void {
    IdentityGenerator::seed();

    for ($i = 0; $i < 10_000; $i++) {
        $panel = IdentityGenerator::panel();
        $local = IdentityGenerator::localKey();
        $key = PermissionKey::of($panel, $local);
        $pattern = PermissionPattern::of($panel, $local.(['', '.*', '.**'][$i % 3]));

        expect(PermissionKey::parse($key->full())->equals($key))->toBeTrue()
            ->and($pattern->full())->toBe($panel.':'.$pattern->local());
    }
});

it('rejects a value outside the key grammar', function (string $panel, string $local, string $exception): void {
    expect(fn () => PermissionKey::of($panel, $local))->toThrow($exception);
})->with([
    'bare one-segment wildcard' => ['admin', '*', InvalidPermissionKeyException::class],
    'bare deep wildcard' => ['admin', '**', InvalidPermissionKeyException::class],
    'pattern as key' => ['admin', 'orders.*', InvalidPermissionKeyException::class],
    'one word' => ['admin', 'view', InvalidPermissionKeyException::class],
    'uppercase' => ['admin', 'Orders.view', InvalidPermissionKeyException::class],
    'space' => ['admin', 'orders. view', InvalidPermissionKeyException::class],
    'invalid panel' => ['Admin', 'orders.view', InvalidPanelIdException::class],
]);

it('rejects a bare wildcard as a pattern', function (string $wildcard): void {
    expect(fn () => PermissionPattern::of('admin', $wildcard))->toThrow(InvalidPermissionKeyException::class);
})->with(['*', '**']);

it('parses only a full permission key', function (string $full, string $exception): void {
    expect(fn () => PermissionKey::parse($full))->toThrow($exception);
})->with([
    ['orders.view', InvalidPermissionKeyException::class],
    ['admin:orders', InvalidPermissionKeyException::class],
    ['a:b:c.d', InvalidPermissionKeyException::class],
    ['Admin:orders.view', InvalidPanelIdException::class],
]);

it('covers a key of the same panel through the matcher', function (): void {
    $deep = PermissionPattern::of('admin', 'orders.**');
    $exact = PermissionPattern::of('admin', 'orders.view');

    expect($deep->covers(PermissionKey::of('admin', 'orders.a.b')))->toBeTrue()
        ->and($deep->covers(PermissionKey::of('crm', 'orders.view')))->toBeFalse()
        ->and(PermissionPattern::of('admin', 'orders.*')->covers(PermissionKey::of('admin', 'orders.a.b')))->toBeFalse()
        ->and($exact->covers(PermissionKey::of('admin', 'orders.view')))->toBeTrue()
        ->and($exact->isExact())->toBeTrue()
        ->and($deep->isExact())->toBeFalse()
        ->and(PermissionPattern::of('admin', 'orders.*')->isExact())->toBeFalse()
        ->and($deep->equals(PermissionPattern::of('admin', 'orders.**')))->toBeTrue()
        ->and($deep->equals(PermissionPattern::of('admin', 'orders.*')))->toBeFalse();
});

it('builds and parses a role key', function (): void {
    $role = RoleKey::of('admin', 'super-admin');

    expect($role->panel())->toBe('admin')
        ->and($role->key())->toBe('super-admin')
        ->and($role->full())->toBe('admin:super-admin')
        ->and(RoleKey::parse('admin:super-admin')->equals($role))->toBeTrue()
        ->and($role->equals(RoleKey::of('crm', 'super-admin')))->toBeFalse();
});

it('rejects an invalid role key', function (callable $make, string $exception): void {
    expect($make)->toThrow($exception);
})->with([
    'underscore' => [fn () => RoleKey::of('admin', 'super_admin'), InvalidRoleKeyException::class],
    'invalid panel' => [fn () => RoleKey::of('Admin', 'manager'), InvalidPanelIdException::class],
    'no colon' => [fn () => RoleKey::parse('manager'), InvalidRoleKeyException::class],
    'two colons' => [fn () => RoleKey::parse('a:b:c'), InvalidRoleKeyException::class],
    'empty key' => [fn () => RoleKey::parse('admin:'), InvalidRoleKeyException::class],
]);
