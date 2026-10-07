<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Storage\Schema\HostKeyColumns;

it('accepts canonical host keys', function (string $type, int|string $id): void {
    expect(HostKeyColumns::canonical($type, $id))->toBe((string) $id);
})->with([
    ['string', '007'], ['bigint', '0'], ['bigint', PHP_INT_MAX],
    ['uuid', '550e8400-e29b-41d4-a716-446655440000'], ['ulid', '01arz3ndektsv4rrffq69g5fav'],
]);

it('rejects lossy or noncanonical host keys', function (string $type, int|string $id): void {
    expect(fn () => HostKeyColumns::canonical($type, $id))->toThrow(InvalidIdentityException::class);
})->with([
    ['string', 'a '], ['bigint', '007'], ['bigint', '-1'], ['bigint', '9223372036854775808'],
    ['uuid', '550E8400-E29B-41D4-A716-446655440000'], ['uuid', '550e8400e29b41d4a716446655440000'],
    ['ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAV'], ['ulid', '01arz3ndektsv4rrffq69g5fa'], ['ulid', '81arz3ndektsv4rrffq69g5fav'], ['bogus', 1],
]);
