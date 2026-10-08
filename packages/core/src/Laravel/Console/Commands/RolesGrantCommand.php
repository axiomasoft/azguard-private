<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use Illuminate\Console\Command;

/**
 * Grants a role to a subject through the change pipeline, as the system actor `azguard:roles:grant`.
 */
final class RolesGrantCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:roles:grant
        {subject : type:id, or the id when there is one subject model}
        {role : Role key, panel:key or code role class}
        {--panel= : The panel; otherwise the panel resolver decides}
        {--on= : type:id of the assignment scope; tenant-wide without it}
        {--until= : ISO-8601 expiry}
        {--field=* : A grant field as key=value; repeat for more}
        {--tenant= : type:id, required for a panel with tenants}
        {--origin= : The origin of the grant (manual by default)}';

    /** @var string */
    protected $description = 'Grant an AzGuard role to a subject';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $subject = $this->subjectRef($this->stringArgument('subject'));
            $role = $this->stringArgument('role');
            $panel = $this->selectPanel($subject, roles: [$role]);
            $access = $this->access($panel, $this->originOption())->for($subject);
            $context = $this->contextOption();
            $until = $this->dateOption('until');
            $fields = $this->fields((array) $this->option('field'));

            return $this->reportChange('Grant of role '.$role.' to '.$subject->key(),
                $this->asSystem(static fn () => $access->grantRole($role, $context, $until, $fields)));
        });
    }
}
