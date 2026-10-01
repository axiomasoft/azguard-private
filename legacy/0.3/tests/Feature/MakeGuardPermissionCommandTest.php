<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function guardPermissionTestToken(): string
{
    $token = getenv('TEST_TOKEN');

    return is_string($token) && $token !== '' ? $token : (string) getmypid();
}

function guardPermissionTestPath(): string
{
    return 'app/Guards/P4Permission'.guardPermissionTestToken();
}

beforeEach(function (): void {
    File::deleteDirectory(directory: base_path(guardPermissionTestPath()));
});

afterEach(function (): void {
    File::deleteDirectory(directory: base_path(guardPermissionTestPath()));
});

it('scaffolds a panel when the permission enum is missing', function (): void {
    $path = guardPermissionTestPath();

    $this->artisan(command: 'make:guard-permission', parameters: [
        'panel' => 'Admin',
        'domain' => 'Documents',
        '--path' => $path,
        '--force' => true,
    ])->assertSuccessful();

    expect(base_path($path.'/Admin/Documents/Permissions/DocumentsPermission.php'))->toBeFile();
});

it('fails when the enum exists but no case name is given', function (): void {
    $path = guardPermissionTestPath();

    $this->artisan(command: 'make:guard-permission', parameters: [
        'panel' => 'Admin',
        'domain' => 'Documents',
        '--path' => $path,
        '--force' => true,
    ])->assertSuccessful();

    $this->artisan(command: 'make:guard-permission', parameters: [
        'panel' => 'Admin',
        'domain' => 'Documents',
        '--path' => $path,
    ])
        ->expectsOutputToContain('Specify a case name')
        ->assertFailed();
});

it('appends a case to an existing permission enum', function (): void {
    $path = guardPermissionTestPath();

    $this->artisan(command: 'make:guard-permission', parameters: [
        'panel' => 'Admin',
        'domain' => 'Documents',
        '--path' => $path,
        '--force' => true,
    ])->assertSuccessful();

    $this->artisan(command: 'make:guard-permission', parameters: [
        'panel' => 'Admin',
        'domain' => 'Documents',
        'name' => 'Export',
        '--path' => $path,
    ])
        ->expectsOutputToContain('Added case Export')
        ->assertSuccessful();

    $enumPath = base_path($path.'/Admin/Documents/Permissions/DocumentsPermission.php');
    $content = File::get(path: $enumPath);

    expect($content)->toContain("case Export = 'documents.export';")
        ->and($content)->toContain("case View = 'documents.view';");
});
