<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Changes\ChangePipeline;
use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use AzGuard\Panels\PanelResolver;
use Illuminate\Console\Command;

/**
 * Gives a panel state a new incarnation and version after a manual change of the tables or a restore of the database:
 * every token, cached decision and permission set of the old state stops passing the fence. Runs under the same lock
 * as every write of the panel, as the system actor `azguard:state:reset`. Production needs `--force`.
 */
final class StateResetCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:state:reset
        {panel : The panel}
        {--force : Reset in production}';

    /** @var string */
    protected $description = 'Start a new AzGuard state of a panel after a manual change or a restore';

    public function handle(ChangePipeline $pipeline, PanelResolver $resolver): int
    {
        return $this->attempt(function () use ($pipeline, $resolver): int {
            $panel = $resolver->select(panels: [$this->stringArgument('panel')]);
            $this->confirmIrreversible('Resetting the state of panel '.$panel->id(), $this->option('force') === true);
            $state = $this->asSystem(static fn () => $pipeline->reset($panel));
            $this->components->info('Panel '.$panel->id().' has a new state: incarnation '.$state->incarnation.', version '.$state->version.'.');

            return self::SUCCESS;
        });
    }
}
