<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use AzGuard\Schema\RoleSchema;
use AzGuard\Sources\Database\DatabaseSource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Lists the code roles of one panel, read-only: how a role is assigned, whether it is a super-admin role, the scope
 * types it is granted in and how many subjects hold a stored grant of it in the tenant. Reads only.
 */
final class RolesListCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:roles:list
        {--panel= : The panel; otherwise the panel resolver decides}
        {--tenant= : type:id, required for a panel with tenants}
        {--json : Print JSON}';

    /** @var string */
    protected $description = 'List the code roles of an AzGuard panel and how many subjects hold them';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $panel = $this->selectPanel();
            $tenant = $this->tenantOf($panel);
            $writer = $panel->writer();
            $holders = $writer instanceof DatabaseSource
                ? $writer->holderCounts($panel, $tenant, CarbonImmutable::now('UTC')->toDateTimeImmutable())
                : null;
            $roles = array_map(static function (RoleSchema $role) use ($holders): array {
                $assigned = array_keys(array_filter(['grant' => $role->grantable, 'automatic' => $role->automatic]));

                return [...$role->toArray(), 'assigned_by' => $assigned, 'holders' => $holders === null ? null : $holders[$role->key->key()] ?? 0];
            }, $this->access($panel)->roles()->all());

            if ($this->option('json') === true) {
                $this->printJson(['panel' => $panel->id(), 'tenant' => $tenant->key(), 'roles' => $roles]);

                return self::SUCCESS;
            }
            $this->table(['Role', 'Label', 'Class', 'Assigned by', 'Super admin', 'Scopes', 'Holders'], array_map(static fn (array $role): array => [
                $role['key'], $role['label'], $role['class'], implode(', ', $role['assigned_by']), $role['super_admin'] ? 'yes' : 'no',
                $role['scope_required'] ? implode(', ', $role['context_types']).' (required)' : implode(', ', $role['context_types']),
                $role['holders'] === null ? '-' : (string) $role['holders'],
            ], $roles));

            return self::SUCCESS;
        });
    }
}
