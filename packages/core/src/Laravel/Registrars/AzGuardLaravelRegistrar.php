<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Registrars;

use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Exceptions\Configuration\InvalidConfigurationException;
use AzGuard\Laravel\Gate\GateBridge;
use AzGuard\Laravel\Internal\About\AboutSection;
use AzGuard\Laravel\Internal\Console\Commands\GrantsPruneCommand;
use AzGuard\Laravel\Internal\Http\Middleware\CheckPermission;
use AzGuard\Laravel\Internal\Http\Middleware\EnterPanel;
use AzGuard\Laravel\Internal\Queue\PanelContext;
use AzGuard\Panels\PanelProvider;
use Illuminate\Auth\Access\Response;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Router;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;
use ReflectionMethod;

#[Internal('Laravel Gate, middleware, scheduler, queue, about, and configured panel provider registrar.')]
final class AzGuardLaravelRegistrar implements PackageRegistrar
{
    public function register(Application $app): void {}

    public function boot(Application $app): void
    {
        $config = $app->make(AzGuardConfig::class);
        $this->registerConfiguredPanels($app, $config);

        $router = $app->make(Router::class);
        $router->aliasMiddleware(EnterPanel::ALIAS, EnterPanel::class);
        $router->aliasMiddleware(CheckPermission::ALIAS, CheckPermission::class);

        $events = $app->make('events');
        $events->listen(RouteMatched::class, [EnterPanel::class, 'outside']);

        if ($config->gateEnabled()) {
            $app->make(Gate::class)->before(
                static fn (?object $user, string $ability, array $arguments): ?Response => $app->make(GateBridge::class)($user, $ability, $arguments),
            );
        }

        if (class_exists(AboutCommand::class)) {
            AboutCommand::add(AboutSection::TITLE, AboutSection::class);
        }

        $events->listen(JobProcessing::class, [PanelContext::class, 'entering']);
        $events->listen([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class], [PanelContext::class, 'leaving']);

        $this->scheduleMaintenance($app, $config);
    }

    /**
     * Providers of modules register themselves; the ones listed in the configuration are registered here.
     *
     * @throws InvalidConfigurationException
     */
    private function registerConfiguredPanels(Application $app, AzGuardConfig $config): void
    {
        foreach ($config->panelProviders() as $provider) {
            if (! is_subclass_of($provider, PanelProvider::class)) {
                throw new InvalidConfigurationException(
                    'azguard.panels.providers lists '.$provider.', which is not a panel provider: it must extend '.PanelProvider::class.'.',
                );
            }

            $app->register($provider);
        }
    }

    private function scheduleMaintenance(Application $app, AzGuardConfig $config): void
    {
        $frequency = $config->pruneExpiredFrequency();

        if (! $config->scheduleEnabled() || $frequency === null) {
            return;
        }

        $configure = static function (Schedule $schedule) use ($frequency): void {
            $event = $schedule->command(GrantsPruneCommand::class);
            str_contains($frequency, ' ') ? $event->cron($frequency) : new ReflectionMethod(Event::class, $frequency)->invoke($event);
        };

        if ($app->resolved(Schedule::class)) {
            $configure($app->make(Schedule::class));

            return;
        }

        $app->afterResolving(Schedule::class, static function (mixed $resolved) use ($configure): void {
            if ($resolved instanceof Schedule) {
                $configure($resolved);
            }
        });
    }
}
