<?php

declare(strict_types=1);

use AzGuard\Tests\Arch\ApiUsage;
use AzGuard\Tests\Arch\Ownership;
use AzGuard\Tests\Arch\SourceScan;

/*
 * I20, blocking now: core calls the methods of `Spi(Implement)` types positionally, so an implementer may rename a
 * parameter (PHP binds a name to the runtime implementation). The rule resolves the called method name against the
 * methods the `Spi(Implement)` types of the manifests declare and reports every call with a named argument in a file
 * core owns, whatever the receiver. A reviewed false positive is fixed by calling positionally, never listed.
 */

it('I20: core calls Spi(Implement) methods positionally', function (): void {
    $root = dirname(__DIR__, 2).'/';
    $ownership = Ownership::fromOracle();
    $core = array_values(array_filter(SourceScan::files(), static fn (string $file): bool => $ownership->fileOwner(str_replace($root, '', $file)) === 'core'));
    $methods = ApiUsage::implementMethods(ApiUsage::supported());

    expect($methods)->toContain('ismember', 'register', 'resolve')
        ->and(ApiUsage::i20Violations($core, $methods))->toBe([], 'call these Spi(Implement) methods positionally');
});

it('I20: reports a named argument of an Spi(Implement) method call and nothing else', function (): void {
    $file = sys_get_temp_dir().'/azguard-i20-'.bin2hex(random_bytes(4)).'.php';
    file_put_contents($file, <<<'PHP'
        <?php
        namespace AzGuard\Changes;
        final class Probe {
            public function run($adapter, $subject, $tenant): bool {
                $adapter->isMember($subject, tenant: $tenant);
                $adapter?->isMember(subject: $subject, tenant: $tenant);
                $adapter->isMember(format(value: 1), [$tenant, 'key' => 1]);
                $adapter->isMember($subject ? A : B, $tenant);
                $adapter->other(subject: $subject);
                Probe::isMember($subject, $tenant);
                return isMember(subject: $subject);
            }
        }
        PHP);

    try {
        $violations = ApiUsage::i20Violations([$file], ['ismember'], dirname($file));
    } finally {
        unlink($file);
    }

    expect($violations)->toBe([basename($file).':5 isMember', basename($file).':6 isMember']);
});
