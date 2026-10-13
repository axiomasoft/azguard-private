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
        ->and([$code->panel(), $code->buildId(), $code->fingerprint()])->toBe(['admin', 'build-1', 'f1'])
        ->and([$stored->storageId(), $stored->panel(), $stored->incarnation(), $stored->version(), $stored->generation(), $stored->fingerprint()])
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

it('keeps what a token records private: no public property, only the supported and internal members', function (string $class): void {
    $reflection = new ReflectionClass($class);

    expect($reflection->getProperties(ReflectionProperty::IS_PUBLIC))->toBe([])
        ->and($reflection->hasMethod('panel') && $reflection->hasMethod('equals') && $reflection->hasMethod('toString') && $reflection->hasMethod('fromString'))->toBeTrue();
})->with([StateToken::class, CodeStateToken::class]);

it('reads back every token from its versioned text form (round trip over 300 generated tokens)', function (): void {
    mt_srand(20261013);

    for ($i = 0; $i < 300; $i++) {
        $panel = ['admin', 'crm', 'back-office', 'p'.$i][$i % 4];
        $stored = StateToken::of(['default', 'tenant-storage', 'ü-store/'.$i][$i % 3], $panel, strtolower(substr(md5((string) mt_rand()), 0, 26)),
            mt_rand(0, PHP_INT_MAX), mt_rand(0, 1000), hash('sha256', (string) $i), mt_rand(0, 50), $i % 3 === 0 ? null : mt_rand(0, PHP_INT_MAX));
        $code = CodeStateToken::of($panel, 'build-'.mt_rand(), hash('sha256', 'code'.$i));
        $storedText = $stored->toString();
        $codeText = $code->toString();

        expect(StateToken::fromString($storedText)->equals($stored))->toBeTrue("state token $i")
            ->and(CodeStateToken::fromString($codeText)->equals($code))->toBeTrue("code token $i")
            ->and(StateToken::fromString($storedText)->toString())->toBe($storedText)
            ->and($storedText)->toStartWith('st1.')->and($codeText)->toStartWith('ct1.')
            ->and(preg_match('/\A(st1|ct1)\.[A-Za-z0-9_-]+\z/', $storedText))->toBe(1)
            ->and(preg_match('/\A(st1|ct1)\.[A-Za-z0-9_-]+\z/', $codeText))->toBe(1);
    }
    expect(StateToken::of('s', 'admin', 'i', 1, 0, 'f')->toString())->not->toBe(StateToken::of('s', 'admin', 'i', 1, 0, 'f', 0, 0)->toString())
        ->and(StateToken::fromString(StateToken::of('s', 'admin', 'i', 1, 0, 'f', 0, 0)->toString())->subjectRevision())->toBe(0);
});

it('refuses text that is not a token of a known version or holds invalid values', function (string $class, string $text): void {
    expect(fn () => $class::fromString($text))->toThrow(InvalidIdentityException::class);
})->with(function (): array {
    $encode = static fn (mixed $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $state = ['storage' => 's', 'panel' => 'admin', 'incarnation' => 'i', 'version' => 1, 'generation' => 0, 'fingerprint' => 'f', 'epoch' => 0, 'subject_revision' => null];
    $code = ['panel' => 'admin', 'build_id' => 'b', 'fingerprint' => 'f'];

    return [
        'empty' => [StateToken::class, ''],
        'code prefix on a state token' => [StateToken::class, CodeStateToken::of('admin', 'b', 'f')->toString()],
        'state prefix on a code token' => [CodeStateToken::class, StateToken::of('s', 'admin', 'i', 1, 0, 'f')->toString()],
        'unknown version' => [StateToken::class, 'st2.'.$encode($state)],
        'not base64url' => [StateToken::class, 'st1.***'],
        'not json' => [StateToken::class, 'st1.'.$encode('x').'%%'],
        'json scalar' => [StateToken::class, 'st1.'.$encode(5)],
        'missing key' => [StateToken::class, 'st1.'.$encode(array_diff_key($state, ['epoch' => 0]))],
        'extra key' => [StateToken::class, 'st1.'.$encode([...$state, 'x' => 1])],
        'reordered keys' => [StateToken::class, 'st1.'.$encode(array_reverse($state))],
        'string counter' => [StateToken::class, 'st1.'.$encode([...$state, 'version' => '1'])],
        'negative counter' => [StateToken::class, 'st1.'.$encode([...$state, 'version' => -1])],
        'invalid panel' => [StateToken::class, 'st1.'.$encode([...$state, 'panel' => 'A:B'])],
        'empty fingerprint' => [StateToken::class, 'st1.'.$encode([...$state, 'fingerprint' => ''])],
        'code missing key' => [CodeStateToken::class, 'ct1.'.$encode(['panel' => 'admin', 'build_id' => 'b'])],
        'code empty build' => [CodeStateToken::class, 'ct1.'.$encode([...$code, 'build_id' => ''])],
        'code invalid panel' => [CodeStateToken::class, 'ct1.'.$encode([...$code, 'panel' => 'Admin'])],
    ];
});

it('serializes the state of an event payload with exactly the keys journal rows always carried', function (): void {
    expect(array_keys(StateToken::of('s', 'admin', 'i', 1, 0, 'f', 3, 7)->payload()))->toBe(['kind', 'storage', 'panel', 'incarnation', 'version', 'generation', 'fingerprint'])
        ->and(array_keys(CodeStateToken::of('admin', 'b', 'f')->payload()))->toBe(['kind', 'panel', 'build_id', 'fingerprint']);
});
