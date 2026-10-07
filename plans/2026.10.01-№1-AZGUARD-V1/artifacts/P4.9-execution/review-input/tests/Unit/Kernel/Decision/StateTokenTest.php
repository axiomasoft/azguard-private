<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\StateToken;

it('separates the code token from the storage token', function (): void {
    $code = CodeStateToken::of('admin', 'build-1', 'f1');
    $stored = StateToken::of('default', 'admin', 'inc-1', 0, 0, 'f1');

    expect($code)->not->toBeInstanceOf(StateToken::class)
        ->and($stored)->not->toBeInstanceOf(CodeStateToken::class)
        ->and([$code->panel, $code->buildId, $code->fingerprint])->toBe(['admin', 'build-1', 'f1'])
        ->and([$stored->storageId, $stored->panel, $stored->incarnation, $stored->version, $stored->generation, $stored->fingerprint])
        ->toBe(['default', 'admin', 'inc-1', 0, 0, 'f1'])
        ->and($code->equals(CodeStateToken::of('admin', 'build-1', 'f1')))->toBeTrue()
        ->and($code->equals(CodeStateToken::of('admin', 'build-2', 'f1')))->toBeFalse()
        ->and($stored->equals(StateToken::of('default', 'admin', 'inc-1', 0, 0, 'f1')))->toBeTrue()
        ->and($stored->equals(StateToken::of('default', 'admin', 'inc-1', 0, 1, 'f1')))->toBeFalse();
});

it('rejects an invalid token', function (callable $make, string $exception): void {
    expect($make)->toThrow($exception);
})->with([
    'code panel' => [fn () => CodeStateToken::of('Admin', 'b', 'f'), InvalidPanelIdException::class],
    'code empty build' => [fn () => CodeStateToken::of('admin', '', 'f'), InvalidIdentityException::class],
    'code empty fingerprint' => [fn () => CodeStateToken::of('admin', 'b', ''), InvalidIdentityException::class],
    'storage panel' => [fn () => StateToken::of('default', 'a:b', 'i', 0, 0, 'f'), InvalidPanelIdException::class],
    'storage empty id' => [fn () => StateToken::of('', 'admin', 'i', 0, 0, 'f'), InvalidIdentityException::class],
    'storage empty incarnation' => [fn () => StateToken::of('default', 'admin', '', 0, 0, 'f'), InvalidIdentityException::class],
    'storage negative version' => [fn () => StateToken::of('default', 'admin', 'i', -1, 0, 'f'), InvalidIdentityException::class],
    'storage negative generation' => [fn () => StateToken::of('default', 'admin', 'i', 0, -1, 'f'), InvalidIdentityException::class],
]);
