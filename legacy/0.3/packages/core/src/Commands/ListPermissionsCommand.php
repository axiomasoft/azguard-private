<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Attributes\GateAbility;
use AzGuard\Facades\AzGuard;
use AzGuard\Guard\PolicyDiscovery;
use Illuminate\Console\Command;
use ReflectionClass;
use ReflectionMethod;

class ListPermissionsCommand extends Command
{
    protected $signature = 'guard:list-permissions {panel? : Panel ID (leave empty for all panels)}';

    protected $description = 'List all registered AzGuard permissions grouped by panel';

    public function handle(): int
    {
        $panelFilter = $this->argument('panel');
        $panels = AzGuard::getPanels();

        if (empty($panels)) {
            $this->warn('No AzGuard panels registered.');

            return self::SUCCESS;
        }

        foreach ($panels as $panelId => $panel) {
            if ($panelFilter !== null && $panelFilter !== $panelId) {
                continue;
            }

            $this->line('');
            $this->info("Panel: <comment>{$panelId}</comment>");

            $basePath = $panel->getBasePath();
            $baseNamespace = $panel->getNamespace();

            if ($basePath === '' || $baseNamespace === '') {
                $this->warn("  basePath/namespace not set for panel [{$panelId}].");

                continue;
            }

            $discovery = new PolicyDiscovery;
            $policyClasses = $discovery->discoverPolicyClasses(
                basePath: $basePath,
                baseNamespace: $baseNamespace,
            );

            $rows = [];
            foreach ($policyClasses as $policyClass) {
                $reflection = new ReflectionClass($policyClass);
                foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    foreach ($method->getAttributes(GateAbility::class) as $attribute) {
                        /** @var GateAbility $ga */
                        $ga = $attribute->newInstance();
                        $ability = $panel->resolvePermission(permission: $ga->permission);
                        $rows[] = [$ability, $policyClass.'::'.$method->getName()];
                    }
                }
            }

            if ($rows === []) {
                $this->line('  No permissions found.');

                continue;
            }

            $this->table(['Permission', 'Handler (Policy::method)'], $rows);
        }

        $this->line('');

        return self::SUCCESS;
    }
}
