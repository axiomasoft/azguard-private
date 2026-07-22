<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace Tests;

// --- Часть 1. Базовый DuskTestCase (tests/DuskTestCase.php) ---
// Ключевые правила:
//   * Dusk-тесты наследуют DuskTestCase (Laravel\Dusk\TestCase), НЕ TestCase.
//   * НИКОГДА RefreshDatabase: транзакция теста невидима для процесса сервера.
//     Вместо этого migrate:fresh --seed в setUp() на изолированной БД app_test.
//   * DUSK_ENV_MODE=test (default) — изоляция + сброс; current — текущее
//     окружение без мутаций (только смоук-сценарии).

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
            // Guard: убедиться, что подключение реально смотрит в app_test,
            // прежде чем сносить схему.
            $database = (string) config('database.connections.'.config('database.default').'.database');

            if (! str_ends_with($database, '_test')) {
                throw new RuntimeException("Dusk требует изолированную БД *_test, получено: {$database}");
            }

            Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        }
    }

    #[BeforeClass]
    public static function prepare(): void
    {
        if (! file_exists(dirname(__DIR__).'/.env.dusk.local')) {
            throw new RuntimeException('.env.dusk.local не найден. Создайте его из .env.dusk.local.example.');
        }

        // В Docker ChromeDriver работает на хосте: DUSK_START_CHROMEDRIVER=false.
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
            // Из Docker: http://host.docker.internal:9515
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

// --- Часть 2. Пример браузерного теста (tests/Browser/AdminLoginTest.php, Pest) ---
//
// use Laravel\Dusk\Browser;
//
// test('admin logs in without redirect to internal port', function () {
//     $this->browse(function (Browser $browser): void {
//         $browser->visit('/admin/login')
//             // Динамический UI — всегда waitFor, не assertSee сразу.
//             ->waitFor(selector: 'input[type="email"]', seconds: 15)
//             ->type(field: 'input[type="email"]', value: 'admin@example.com')
//             ->type(field: 'input[type="password"]', value: 'secret')
//             ->press(button: 'Войти')
//             // Произвольное условие с поллингом вместо sleep.
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
