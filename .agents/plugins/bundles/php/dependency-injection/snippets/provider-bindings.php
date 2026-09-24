<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

// ============================================================================
// ПО УМОЛЧАНИЮ ЭТОТ ФАЙЛ НЕ НУЖЕН.
//
// Контейнер Laravel резолвит КОНКРЕТНЫЕ классы zero-config: если у класса
// type-hinted конструктор, никаких биндингов писать не надо. В эталонном
// проекте AppServiceProvider::register() не содержит ни одного bind()
// для доменных классов — и это норма.
//
// Интерфейс + биндинг заводят ТОЛЬКО при реальной вариативности реализаций:
//   - драйверы (несколько взаимозаменяемых реализаций: SMS-шлюзы, хранилища);
//   - внешние интеграции (HTTP-клиент, который в тестах подменяется фейком);
//   - подмена сложной зависимости в тестах, когда mock конкретного класса
//     неудобен (final, тяжелая инициализация).
//
// «Интерфейс на каждый сервис» — карго-культ: лишний файл, лишний биндинг,
// прыжок при навигации, и всё ради единственной реализации.
// ============================================================================

namespace App\Contracts\Notification;

use App\Models\User;

/**
 * Контракт оправдан: есть минимум две реальные реализации (шлюз + лог-заглушка).
 */
interface SmsGatewayContract
{
    public function send(User $user, string $message): void;
}

namespace App\Providers;

use App\Contracts\Notification\SmsGatewayContract;
use App\Services\Document\Export\ExportService;
use App\Services\Notification\LogSmsGateway;
use App\Services\Notification\VendorSmsGateway;
use App\Services\Pdf\ChromiumPdfRenderer;
use App\Services\Pdf\PdfRendererContract;
use App\Services\Pdf\WkhtmlPdfRenderer;
use Illuminate\Support\ServiceProvider;
use Override;

final class DomainServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        // 1. Обычный bind: реализация выбирается конфигом (драйверная вариативность).
        $this->app->bind(
            abstract: SmsGatewayContract::class,
            concrete: fn (): SmsGatewayContract => config('services.sms.enabled')
                ? $this->app->make(VendorSmsGateway::class)
                : $this->app->make(LogSmsGateway::class),
        );

        // 2. Singleton — только для дорогих в создании stateless-сервисов
        //    (держит соединение, кэширует конфиг). НЕ дефолт.
        $this->app->singleton(abstract: ChromiumPdfRenderer::class);

        // 3. Контекстуальный биндинг: один потребитель получает особую реализацию,
        //    остальные — дефолтную. Применять точечно, это исключение.
        $this->app->when(ExportService::class)
            ->needs(PdfRendererContract::class)
            ->give(WkhtmlPdfRenderer::class);

        $this->app->bind(
            abstract: PdfRendererContract::class,
            concrete: ChromiumPdfRenderer::class,
        );
    }
}
