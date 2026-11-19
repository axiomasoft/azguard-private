<?php

declare(strict_types=1);

use AzGuard\AzGuardServiceProvider;
use AzGuard\Configuration\AzGuardOptions;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Exceptions\Configuration\InvalidConfigurationException;
use Illuminate\Config\Repository;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

it('boots AzGuardServiceProvider cleanly when azguard.scaffold is configured without DevTools loaded', function (): void {
    $case = new class('no-dev-scaffold-boot') extends OrchestraTestCase
    {
        protected function getPackageProviders($app): array
        {
            return [AzGuardServiceProvider::class];
        }

        protected function getEnvironmentSetUp($app): void
        {
            $app['config']->set('azguard.scaffold', [
                'namespace' => 'App\\Access',
                'path' => 'app/Access',
            ]);
        }
    };

    $app = $case->createApplication();
    $loaded = array_keys($app->getLoadedProviders());

    expect(array_filter($loaded, static fn (string $provider): bool => str_contains($provider, 'DevTools')))->toBe([])
        ->and($app->make(AzGuardOptions::class))->toBeInstanceOf(AzGuardOptions::class)
        ->and($app->make(AzGuardConfig::class))->toBeInstanceOf(AzGuardConfig::class);
});

it('validates azguard.scaffold shape strictly when DevTools is absent', function (): void {
    $repo = new Repository([
        'azguard' => [
            'scaffold' => ['unexpected' => 'nope'],
        ],
    ]);

    expect(static fn (): AzGuardOptions => AzGuardOptions::fromRepository($repo))
        ->toThrow(InvalidConfigurationException::class, 'Unknown key in azguard.scaffold: unexpected');
});
