<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use AzGuard\Panels\PanelResolver;
use Illuminate\Console\Command;

/**
 * Creates a dynamic permission in one tenant of a panel that declares `dynamicPermissions()`, through the change
 * pipeline as the system actor `azguard:permissions:create`.
 */
final class PermissionsCreateCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:permissions:create
        {name : Name of the permission, or panel:name}
        {--panel= : The panel; otherwise the panel resolver decides}
        {--label= : The label}
        {--group= : The group}
        {--tenant= : type:id, required for a panel with tenants}';

    /** @var string */
    protected $description = 'Create a dynamic AzGuard permission';

    public function handle(PanelResolver $resolver): int
    {
        return $this->attempt(function () use ($resolver): int {
            $name = $this->stringArgument('name');
            $panel = $this->selectPanel(permissions: [$name]);
            $local = $resolver->pattern($panel, $name)->local();
            $access = $this->access($panel);
            $label = $this->stringOption('label');
            $group = $this->stringOption('group');

            return $this->reportChange('Creation of permission '.$local,
                $this->asSystem(static fn () => $access->permissions()->create($local, $label, $group)));
        });
    }
}
