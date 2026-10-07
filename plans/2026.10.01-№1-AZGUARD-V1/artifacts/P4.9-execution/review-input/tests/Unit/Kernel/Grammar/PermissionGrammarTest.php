<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Exceptions\InvalidRoleKeyException;
use AzGuard\Kernel\Grammar\PermissionGrammar;

dataset('local keys and patterns', [
    'two segments' => ['a.b', true, true],
    'typical key' => ['orders.view', true, true],
    'digits' => ['0.1', true, true],
    'dash and underscore inside' => ['a-b.c_d', true, true],
    'trailing dash in segment' => ['a.b-', true, true],
    'three segments' => ['orders.view.all', true, true],
    'exactly 255 bytes' => ['a.'.str_repeat('b', 253), true, true],
    '256 bytes' => ['a.'.str_repeat('b', 254), false, false],
    'single segment' => ['a', false, false],
    'single word' => ['view', false, false],
    'empty' => ['', false, false],
    'uppercase' => ['A.b', false, false],
    'space in segment' => ['a .b', false, false],
    'space between words' => ['a.b c', false, false],
    'empty middle segment' => ['a..b', false, false],
    'leading dot' => ['.a.b', false, false],
    'trailing dot' => ['a.b.', false, false],
    'leading underscore' => ['_a.b', false, false],
    'leading dash' => ['-a.b', false, false],
    'trailing newline' => ["a.b\n", false, false],
    'null byte' => ["a.b\0", false, false],
    'non-ascii' => ['а.b', false, false],
    'colon' => ['admin:orders.view', false, false],
    'one-segment wildcard last' => ['orders.*', false, true],
    'deep wildcard last' => ['orders.**', false, true],
    'wildcard after three segments' => ['orders.view.*', false, true],
    'bare one-segment wildcard' => ['*', false, false],
    'bare deep wildcard' => ['**', false, false],
    'two wildcards' => ['*.*', false, false],
    'wildcard first' => ['*.a', false, false],
    'one-segment wildcard in the middle' => ['a.*.b', false, false],
    'deep wildcard in the middle' => ['a.**.b', false, false],
    'wildcard before wildcard' => ['a.*.*', false, false],
    'triple star' => ['a.***', false, false],
    'star glued to a name' => ['a.b*', false, false],
    'star before a name' => ['a.*b', false, false],
]);

it('classifies a local key and a pattern', function (string $input, bool $key, bool $pattern): void {
    expect(PermissionGrammar::isLocalKey($input))->toBe($key)
        ->and(PermissionGrammar::isPattern($input))->toBe($pattern);
})->with('local keys and patterns');

it('asserts a local key and a pattern with the permission key exception', function (string $input, bool $key, bool $pattern): void {
    foreach ([[$key, PermissionGrammar::assertLocalKey(...)], [$pattern, PermissionGrammar::assertPattern(...)]] as [$valid, $assert]) {
        $valid
            ? expect(fn () => $assert($input))->not->toThrow(Throwable::class)
            : expect(fn () => $assert($input))->toThrow(InvalidPermissionKeyException::class);
    }
})->with('local keys and patterns');

it('validates and splits a full key', function (string $input, ?string $exception): void {
    expect(PermissionGrammar::isFullKey($input))->toBe($exception === null);

    if ($exception !== null) {
        expect(fn () => PermissionGrammar::splitFull($input))->toThrow($exception);

        return;
    }

    expect(PermissionGrammar::splitFull($input))->toBe(explode(':', $input));
})->with([
    'panel and local' => ['admin:orders.view', null],
    'dashed panel' => ['admin-2:a.b', null],
    'panel of 64' => [str_repeat('p', 64).':a.b', null],
    'panel of 65' => [str_repeat('p', 65).':a.b', InvalidPanelIdException::class],
    'one-segment local' => ['admin:orders', InvalidPermissionKeyException::class],
    'two colons' => ['a:b:c.d', InvalidPermissionKeyException::class],
    'no colon' => ['orders.view', InvalidPermissionKeyException::class],
    'uppercase panel' => ['Admin:a.b', InvalidPanelIdException::class],
    'underscore panel' => ['admin_x:a.b', InvalidPanelIdException::class],
    'empty panel' => [':a.b', InvalidPanelIdException::class],
    'empty local' => ['admin:', InvalidPermissionKeyException::class],
    'pattern as local' => ['admin:orders.*', InvalidPermissionKeyException::class],
]);

it('validates a panel id and a role key with one id rule', function (string $input, bool $valid): void {
    expect(PermissionGrammar::isPanelId($input))->toBe($valid)
        ->and(PermissionGrammar::isRoleKey($input))->toBe($valid);

    if ($valid) {
        PermissionGrammar::assertPanelId($input);
        PermissionGrammar::assertRoleKey($input);

        return;
    }

    expect(fn () => PermissionGrammar::assertPanelId($input))->toThrow(InvalidPanelIdException::class)
        ->and(fn () => PermissionGrammar::assertRoleKey($input))->toThrow(InvalidRoleKeyException::class);
})->with([
    'word' => ['admin', true],
    'one letter' => ['a', true],
    'digit' => ['0', true],
    'dash inside' => ['super-admin', true],
    'length 64' => [str_repeat('a', 64), true],
    'length 65' => [str_repeat('a', 65), false],
    'empty' => ['', false],
    'leading dash' => ['-a', false],
    'underscore' => ['a_b', false],
    'uppercase' => ['Admin', false],
    'dot' => ['a.b', false],
    'trailing newline' => ["admin\n", false],
]);

it('accepts exactly the segment rule', function (string $input, bool $valid): void {
    expect(PermissionGrammar::isSegment($input))->toBe($valid);
})->with([
    ['orders', true],
    ['a_b-c', true],
    ['9x', true],
    ['', false],
    ['*', false],
    ['_a', false],
    ['a.b', false],
    ["a\n", false],
]);

it('names the value and the rule in the message', function (): void {
    expect(fn () => PermissionGrammar::assertLocalKey('A.b'))
        ->toThrow(InvalidPermissionKeyException::class, 'Invalid permission key "A.b": segment "A" must match')
        ->and(fn () => PermissionGrammar::assertPattern('a.*.b'))
        ->toThrow(InvalidPermissionKeyException::class, 'Invalid permission pattern "a.*.b": may use a wildcard only as the last segment.')
        ->and(fn () => PermissionGrammar::assertLocalKey("a\0"))
        ->toThrow(InvalidPermissionKeyException::class, 'Invalid permission key "a\u0000": must have at least two dot-separated segments.')
        ->and(fn () => PermissionGrammar::assertPanelId('Admin'))
        ->toThrow(InvalidPanelIdException::class, 'Invalid panel id "Admin": must match');
});

it('exposes the grammar limits as constants', function (): void {
    expect(PermissionGrammar::MAX_LOCAL_LENGTH)->toBe(255)
        ->and(PermissionGrammar::WILDCARD_ONE)->toBe('*')
        ->and(PermissionGrammar::WILDCARD_DEEP)->toBe('**');
});
