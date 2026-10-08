<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Queue;

use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use WeakMap;

/**
 * Carries the panel of a request to the queued jobs it dispatches, as a hint for the short names a job uses.
 *
 * `azguard.panel` writes the id of the panel into the hidden Laravel context while the request runs, so every job
 * dispatched inside it carries the id. While the job runs, that id is the current panel: short permission names and
 * models resolve against it, as in the request. A job dispatched outside a panel request, from the console for one,
 * carries no id and uses the default rule. An id that no registered panel has, after a deploy removed the panel, is
 * not replaced by the default panel: the short names of the job are refused.
 *
 * The hint is not authority. The context holds nothing but the id, and the job authorizes its subject, tenant and
 * resource again from the references it carries. The panel before the job, which the request of a `sync` queue still
 * runs in, comes back when the job ends, also when it throws.
 *
 * @internal
 */
final class PanelContext
{
    public const string KEY = 'azguard.panel';

    /** @var WeakMap<Job, array{0: Panel|string|null}> the panel to restore, by the job that replaced it */
    private WeakMap $running;

    public function __construct(private readonly Container $container)
    {
        $this->running = new WeakMap;
    }

    /**
     * Runs the callback with the panel as the hint of the jobs dispatched meanwhile; the previous hint comes back
     * afterwards.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function carry(Panel $panel, Closure $callback): mixed
    {
        $context = $this->container->make(Repository::class);
        $previous = $context->getHidden(self::KEY);
        $context->addHidden(self::KEY, $panel->id());

        try {
            return $callback();
        } finally {
            $previous === null ? $context->forgetHidden(self::KEY) : $context->addHidden(self::KEY, $previous);
        }
    }

    /**
     * The job starts: its context is hydrated already, so the hint is the one of the dispatching request.
     */
    public function entering(JobProcessing $event): void
    {
        $current = $this->container->make(CurrentPanel::class);
        $this->running[$event->job] = [$current->rejected() ?? $current->get()];

        $hint = $this->container->make(Repository::class)->getHidden(self::KEY);

        if ($hint === null) {
            $current->set(null);

            return;
        }
        $panel = is_string($hint) ? $this->container->make(PanelRegistry::class)->find($hint) : null;
        $panel === null ? $current->reject(is_string($hint) ? $hint : get_debug_type($hint)) : $current->set($panel);
    }

    /**
     * The job ends, by success, exception or failure; the first of these events restores the panel.
     */
    public function leaving(JobProcessed|JobExceptionOccurred|JobFailed $event): void
    {
        if (! isset($this->running[$event->job])) {
            return;
        }
        [$previous] = $this->running[$event->job];
        unset($this->running[$event->job]);

        $current = $this->container->make(CurrentPanel::class);
        is_string($previous) ? $current->reject($previous) : $current->set($previous);
    }
}
