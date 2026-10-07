<?php

declare(strict_types=1);

use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Kernel\Grammar\PatternMatcher;
use AzGuard\Kernel\Grammar\PermissionGrammar;

it('rejects bare wildcards as a permission key and as a grant pattern', function (string $wildcard): void {
    expect(PermissionGrammar::isLocalKey($wildcard))->toBeFalse()
        ->and(PermissionGrammar::isPattern($wildcard))->toBeFalse()
        ->and(PermissionGrammar::isFullKey('admin:'.$wildcard))->toBeFalse()
        ->and(fn () => PermissionGrammar::assertLocalKey($wildcard))->toThrow(InvalidPermissionKeyException::class)
        ->and(fn () => PermissionGrammar::assertPattern($wildcard))->toThrow(InvalidPermissionKeyException::class)
        ->and(fn () => PermissionGrammar::splitFull('admin:'.$wildcard))->toThrow(InvalidPermissionKeyException::class)
        ->and(fn () => PatternMatcher::covers($wildcard, 'orders.view'))->toThrow(InvalidPermissionKeyException::class);
})->with(['*', '**']);
