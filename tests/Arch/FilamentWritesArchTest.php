<?php

declare(strict_types=1);

use AzGuard\Tests\Arch\SourceScan;

/*
 * I1: the Filament package writes only through the public API of the core (SubjectAccess, GrantManager, PermissionManager).
 * It names no storage class and no database facade, so it has no way to create, save, update, delete, insert or upsert a
 * row of its own.
 */

const FILAMENT_FORBIDDEN_DEPENDENCIES = [
    'AzGuard\Storage',
    'Illuminate\Support\Facades\DB',
    'Illuminate\Database\DatabaseManager',
    'Illuminate\Database\Connection',
    'Illuminate\Database\ConnectionInterface',
    'Illuminate\Database\Query\Builder',
];

it('keeps the Filament package away from storage models and the database layer', function (): void {
    $files = SourceScan::files('packages/filament/src');

    expect($files)->not->toBe([])
        ->and(SourceScan::modelStaticCallsIn($files))->toBe([])
        ->and(SourceScan::restrictedReferencesIn($files, FILAMENT_FORBIDDEN_DEPENDENCIES, []))->toBe([]);
});

it('proves the Filament write boundary RED against a scratch copy', function (string $body): void {
    $directory = sys_get_temp_dir().'/azguard-filament-arch-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $file = $directory.'/Writes.php';
    file_put_contents($file, "<?php\nnamespace AzGuard\\Filament;\n".$body);

    try {
        expect(SourceScan::restrictedReferencesIn([$file], FILAMENT_FORBIDDEN_DEPENDENCIES, []))->not->toBe([])
            ->and(SourceScan::restrictedReferencesIn(SourceScan::files('packages/filament/src'), FILAMENT_FORBIDDEN_DEPENDENCIES, []))->toBe([]);
    } finally {
        unlink($file);
        rmdir($directory);
    }
})->with([
    'a model write' => ["use AzGuard\\Storage\\Models\\RoleGrant;\nfinal class Writes { public function bad(): void { RoleGrant::query()->create([]); } }"],
    'the facade' => ["use Illuminate\\Support\\Facades\\DB;\nfinal class Writes { public function bad(): void { DB::table('azg_role_grants')->insert([]); } }"],
    'a fully qualified connection' => ["final class Writes { public function bad(\\Illuminate\\Database\\Connection \$connection): void { \$connection->table('x')->upsert([], []); } }"],
]);

it('catches a model write that names the model by its class', function (): void {
    $directory = sys_get_temp_dir().'/azguard-filament-model-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $file = $directory.'/Writes.php';
    file_put_contents($file, "<?php\nnamespace AzGuard\\Filament;\nuse AzGuard\\Storage\\Models\\PermissionGrant as Grant;\nfinal class Writes { public function bad(): void { Grant::create([]); } }");

    try {
        expect(SourceScan::modelStaticCallsIn([$file]))->toHaveCount(1);
    } finally {
        unlink($file);
        rmdir($directory);
    }
});
