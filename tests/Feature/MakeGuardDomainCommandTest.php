<?php

declare(strict_types=1);

use AzGuard\Tests\Stubs\Document;
use AzGuard\Tests\Stubs\Invoice;
use Illuminate\Support\Facades\File;

function guardDomainTestToken(): string
{
    $token = getenv('TEST_TOKEN');

    return is_string($token) && $token !== '' ? $token : (string) getmypid();
}

function guardDomainTestPath(string $suffix): string
{
    return 'app/Guards/P5Domain'.guardDomainTestToken().'/'.$suffix;
}

it('добавляет домен в существующую панель и регистрирует enum в provider', function (): void {
    $path = guardDomainTestPath(suffix: 'Add');
    File::deleteDirectory(base_path($path));

    $this->artisan(
        command: 'make:guard-panel',
        parameters: [
            'panel' => 'Admin',
            'domain' => 'Documents',
            '--path' => $path,
            '--model' => Document::class,
        ],
    )->assertSuccessful();

    $basePath = base_path($path.'/Admin');
    $documentsPolicy = File::get(path: $basePath.'/Documents/Policies/DocumentsPolicy.php');

    $this->artisan(
        command: 'make:guard-domain',
        parameters: [
            'panel' => 'Admin',
            'domain' => 'Invoices',
            '--path' => $path,
            '--model' => Invoice::class,
        ],
    )->assertSuccessful();

    expect($basePath.'/Invoices/Permissions/InvoicesPermission.php')->toBeFile()
        ->and(File::get(path: $basePath.'/Documents/Policies/DocumentsPolicy.php'))->toBe($documentsPolicy);

    $provider = File::get(path: $basePath.'/AdminGuardPanelProvider.php');
    expect($provider)->toContain('DocumentsPermission::class')
        ->and($provider)->toContain('InvoicesPermission::class');
});

it('повторный идентичный make:guard-domain не меняет байты', function (): void {
    $path = guardDomainTestPath(suffix: 'Noop');
    File::deleteDirectory(base_path($path));

    $this->artisan(
        command: 'make:guard-panel',
        parameters: [
            'panel' => 'Admin',
            'domain' => 'Documents',
            '--path' => $path,
            '--model' => Document::class,
        ],
    )->assertSuccessful();

    $this->artisan(
        command: 'make:guard-domain',
        parameters: [
            'panel' => 'Admin',
            'domain' => 'Invoices',
            '--path' => $path,
            '--model' => Invoice::class,
        ],
    )->assertSuccessful();

    $snapshot = [];
    foreach (File::allFiles(directory: base_path($path)) as $file) {
        $snapshot[$file->getPathname()] = File::get(path: $file->getPathname());
    }

    $this->artisan(
        command: 'make:guard-domain',
        parameters: [
            'panel' => 'Admin',
            'domain' => 'Invoices',
            '--path' => $path,
            '--model' => Invoice::class,
        ],
    )->assertSuccessful();

    foreach ($snapshot as $pathname => $bytes) {
        expect(File::get(path: $pathname))->toBe($bytes);
    }
});

it('требует --model для add-domain без config mapping', function (): void {
    $path = guardDomainTestPath(suffix: 'ModelRequired');
    File::deleteDirectory(base_path($path));

    $this->artisan(
        command: 'make:guard-panel',
        parameters: [
            'panel' => 'Admin',
            'domain' => 'Documents',
            '--path' => $path,
            '--model' => Document::class,
        ],
    )->assertSuccessful();

    $this->artisan(
        command: 'make:guard-domain',
        parameters: [
            'panel' => 'Admin',
            'domain' => 'Invoices',
            '--path' => $path,
        ],
    )->assertFailed();
});

it('adds multiple domains and accepts a configured model mapping', function (): void {
    $path = guardDomainTestPath(suffix: 'Multiple');
    File::deleteDirectory(base_path($path));
    $this->artisan(command: 'make:guard-panel', parameters: [
        'panel' => 'Admin', 'domain' => 'Documents', '--path' => $path, '--model' => Document::class,
    ])->assertSuccessful();

    config(['az-guard.scaffold.domain_models.admin.invoices' => Invoice::class]);
    $this->artisan(command: 'make:guard-domain', parameters: [
        'panel' => 'Admin', 'domain' => 'Invoices', '--path' => $path,
    ])->assertSuccessful();
    $this->artisan(command: 'make:guard-domain', parameters: [
        'panel' => 'Admin', 'domain' => 'Reports', '--path' => $path, '--model' => Document::class,
    ])->assertSuccessful();

    $provider = File::get(path: base_path($path.'/Admin/AdminGuardPanelProvider.php'));
    expect($provider)->toContain('DocumentsPermission::class')
        ->toContain('InvoicesPermission::class')
        ->toContain('ReportsPermission::class');
});

it('rejects a custom provider before creating domain files', function (): void {
    $path = guardDomainTestPath(suffix: 'CustomProvider');
    File::deleteDirectory(base_path($path));
    $this->artisan(command: 'make:guard-panel', parameters: [
        'panel' => 'Admin', 'domain' => 'Documents', '--path' => $path, '--model' => Document::class,
    ])->assertSuccessful();

    $providerPath = base_path($path.'/Admin/AdminGuardPanelProvider.php');
    $custom = File::get(path: $providerPath)."\n// application customization\n";
    File::put(path: $providerPath, contents: $custom);
    $this->artisan(command: 'make:guard-domain', parameters: [
        'panel' => 'Admin', 'domain' => 'Invoices', '--path' => $path, '--model' => Invoice::class,
    ])->assertFailed();

    expect(File::get(path: $providerPath))->toBe($custom)
        ->and(base_path($path.'/Admin/Invoices'))->not->toBeDirectory();
});

it('does not register the initial domain enum twice', function (): void {
    $path = guardDomainTestPath(suffix: 'InitialDomain');
    File::deleteDirectory(base_path($path));
    $parameters = ['panel' => 'Admin', 'domain' => 'Documents', '--path' => $path, '--model' => Document::class];

    $this->artisan(command: 'make:guard-panel', parameters: $parameters)->assertSuccessful();
    $providerPath = base_path($path.'/Admin/AdminGuardPanelProvider.php');
    $before = File::get(path: $providerPath);

    $this->artisan(command: 'make:guard-domain', parameters: $parameters)->assertSuccessful();

    expect(File::get(path: $providerPath))->toBe($before);
});
