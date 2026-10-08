<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use AzGuard\Panels\PanelResolver;
use Illuminate\Console\Command;

/**
 * Deletes a dynamic permission of one tenant with every stored grant of exactly that name, in one mutation of the
 * change pipeline as the system actor `azguard:permissions:delete`. Irreversible: production needs `--force`.
 */
final class PermissionsDeleteCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:permissions:delete
        {name : Name of the permission, or panel:name}
        {--panel= : The panel; otherwise the panel resolver decides}
        {--tenant= : type:id, required for a panel with tenants}
        {--force : Delete in production}';

    /** @var string */
    protected $description = 'Delete a dynamic AzGuard permission and its grants';

    public function handle(PanelResolver $resolver): int
    {
        return $this->attempt(function () use ($resolver): int {
            $name = $this->stringArgument('name');
            $panel = $this->selectPanel(permissions: [$name]);
            $local = $resolver->pattern($panel, $name)->local();
            $access = $this->access($panel);
            $this->confirmIrreversible('Deleting permission '.$local.' and its grants', $this->option('force') === true);

            return $this->reportChange('Deletion of permission '.$local,
                $this->asSystem(static fn () => $access->permissions()->delete($local)));
        });
    }
}
