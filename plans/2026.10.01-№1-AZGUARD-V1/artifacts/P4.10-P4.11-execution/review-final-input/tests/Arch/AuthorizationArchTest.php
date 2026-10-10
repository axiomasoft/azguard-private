<?php

declare(strict_types=1);

use AzGuard\Tests\Arch\SourceScan;

arch('authorization coordinator never reaches ambient auth or framework gate')
    ->expect('AzGuard\Authorization')->not->toUse(['Illuminate\Support\Facades\Auth', 'Illuminate\Support\Facades\Gate', 'AzGuard\Changes']);
arch('sources use contracts and values without importing authorization implementations')
    ->expect('AzGuard\Sources')->not->toUse(['AzGuard\Authorization']);
it('proves the authorization source boundary RED against a scratch copy', function (): void {
    $directory = sys_get_temp_dir().'/azguard-authority-arch-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $file = $directory.'/InjectedSource.php';
    file_put_contents($file, "<?php\nnamespace AzGuard\\Sources\\Probe;\nuse AzGuard\\Authorization\\Authorizer;\nfinal class InjectedSource { public function __construct(Authorizer \$engine) {} }\n");
    $dependencies = ['AzGuard\\Authorization'];
    expect(SourceScan::restrictedReferencesIn([$file], $dependencies, []))->not->toBe([])
        ->and(SourceScan::restrictedReferencesIn(SourceScan::files('packages/core/src/Sources'), $dependencies, []))->toBe([]);
});

it('keeps the coordinator provenance exception read only', function (): void {
    expect(azguardTransactionCalls(dirname(__DIR__, 2).'/packages/core/src/Authorization/Pipeline/Stages/AuthorityStage.php'))->toBe([]);
});
