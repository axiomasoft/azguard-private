<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use AzGuard\Schema\PermissionSchema;
use Illuminate\Console\Command;

/**
 * Lists the permissions of one panel: static ones and the dynamic ones of the tenant, with their group, the sources
 * that can give them and the policy that decides them. Reads only.
 */
final class CatalogListCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:catalog:list
        {--panel= : The panel; otherwise the panel resolver decides}
        {--tenant= : type:id, required for a panel with tenants}
        {--json : Print JSON}';

    /** @var string */
    protected $description = 'List the permissions of an AzGuard panel';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $panel = $this->selectPanel();
            $permissions = array_map(static fn (PermissionSchema $permission): array => $permission->toArray(), $this->access($panel)->schema()->permissions());

            if ($this->option('json') === true) {
                $this->printJson(['panel' => $panel->id(), 'tenant' => $this->tenantOf($panel)->key(), 'permissions' => $permissions]);

                return self::SUCCESS;
            }
            $this->table(['Permission', 'Label', 'Group', 'Kind', 'Authority', 'Sources', 'Policy'], array_map(static fn (array $permission): array => [
                $permission['name'], $permission['label'], $permission['group'] ?? '', $permission['dynamic'] ? 'dynamic' : 'static',
                $permission['authority'], implode(', ', $permission['sources']), $permission['decided_by'] ?? '',
            ], $permissions));

            return self::SUCCESS;
        });
    }
}
