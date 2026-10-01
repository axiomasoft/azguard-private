<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace Tests;

// --- Part 1. Basic DuskTestCase (tests/DuskTestCase.php) ---
// Key rules:
//   * Dusk-tests inherit DuskTestCase (Laravel\Dusk\TestCase), NOT TestCase.
//   * NEVER RefreshDatabase: test transaction is not visible to the server process.
//     Instead migrate:fresh --seed in setUp() on an isolated database app_test.
//   * DUSK_ENV_MODE=test (default) — insulation + reset; current — current
//     environment without mutations (smoke scripts only).

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;
use RuntimeException;

abstract class DuskTestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        if (static::isTestEnvironmentMode()) {
            // Guard: make sure that the connection actually looks into app_test,
            // before tearing down the circuit.
            $database = (string) config('database.connections.'.config('database.default').'.database');

            if (! str_ends_with($database, '_test')) {
                throw new RuntimeException("Dusk requires isolated database *_test, received: {$database}");
            }

            Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        }
    }

    #[BeforeClass]
    public static function prepare(): void
    {
        if (! file_exists(dirname(__DIR__).'/.env.dusk.local')) {
            throw new RuntimeException('.env.dusk.local not found. Create it from .env.dusk.local.example.');
        }

        // B Docker ChromeDriver runs on the host: DUSK_START_CHROMEDRIVER=false.
        if (! static::runningInSail() && static::envBool('DUSK_START_CHROMEDRIVER', true)) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(array_filter([
            '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            static::envBool('DUSK_HEADLESS', true) ? '--headless=new' : null,
            static::envBool('DUSK_HEADLESS', true) ? '--disable-gpu' : null,
        ]));

        return RemoteWebDriver::create(
            // From Docker: http://host.docker.internal:9515
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options),
        );
    }

    protected static function isTestEnvironmentMode(): bool
    {
        return strtolower((string) ($_ENV['DUSK_ENV_MODE'] ?? env('DUSK_ENV_MODE', 'test'))) === 'test';
    }

    protected static function envBool(string $key, bool $default): bool
    {
        return filter_var($_ENV[$key] ?? env($key, $default), FILTER_VALIDATE_BOOLEAN);
    }
}

// --- Part 2. Browser test example (tests/Browser/AdminLoginTest.php, Pest) ---
//
// use Laravel\Dusk\Browser;
//
// test('admin logs in without redirect to internal port', function () {
//     $this->browse(function (Browser $browser): void {
//         $browser->visit('/admin/login')
//             // Dynamic UI — always waitFor, not assertSee immediately.
//             ->waitFor(selector: 'input[type="email"]', seconds: 15)
//             ->type(field: 'input[type="email"]', value: 'admin@example.com')
//             ->type(field: 'input[type="password"]', value: 'secret')
//             ->press(button: 'Login')
//             // Arbitrary condition with polling instead sleep.
//             ->waitUsing(
//                 seconds: 25,
//                 interval: 200,
//                 callback: static function () use ($browser): bool {
//                     $path = parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH) ?? '';
//
//                     return str_starts_with($path, '/admin') && ! str_contains($path, 'login');
//                 },
//             )
//             ->assertPathBeginsWith('/admin')
//             ->assertSee('Dashboard');
//     });
// });
