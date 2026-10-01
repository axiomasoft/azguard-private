<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

// ============================================================================
// BY DEFAULT THIS FILE IS NOT REQUIRED.
//
// Container Laravel resolves SPECIFIC classes zero-config: if the class
// type-hinted constructor, no need to write any bindings. In reference
// project AppServiceProvider::register() does not contain any bind()
// for domain classes - and this is the norm.
//
// Interface + binding is started ONLY when there is real variability in implementations:
//   - drivers (several interchangeable implementations: SMS-gateways, storage);
//   - external integrations (HTTP-client, which is replaced by a fake in tests);
//   - replacing a complex dependency in tests when mock specific class
//     inconvenient (final, heavy initialization).
//
// «Interface for each service» — cargo cult: extra file, extra binding,
// jump during navigation, all for the sake of a single implementation.
// ============================================================================

namespace App\Contracts\Notification;

use App\Models\User;

/**
 * The contract is justified: there are at least two real implementations (gateway + log stub).
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
        // 1. Normal bind: implementation is selected by the config (driver variability).
        $this->app->bind(
            abstract: SmsGatewayContract::class,
            concrete: fn (): SmsGatewayContract => config('services.sms.enabled')
                ? $this->app->make(VendorSmsGateway::class)
                : $this->app->make(LogSmsGateway::class),
        );

        // 2. Singleton — only for those who are expensive to create stateless-services
        //    (holds the connection, caches the config). NOT default.
        $this->app->singleton(abstract: ChromiumPdfRenderer::class);

        // 3. Contextual binding: one consumer gets a specific implementation,
        //    the rest - default. To apply it pointwise is an exception.
        $this->app->when(ExportService::class)
            ->needs(PdfRendererContract::class)
            ->give(WkhtmlPdfRenderer::class);

        $this->app->bind(
            abstract: PdfRendererContract::class,
            concrete: ChromiumPdfRenderer::class,
        );
    }
}
