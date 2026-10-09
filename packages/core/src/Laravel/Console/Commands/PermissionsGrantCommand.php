<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Changes\ChangeResult;
use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use Illuminate\Console\Command;

/**
 * Grants a permission or a pattern directly to a subject through the change pipeline, as the system actor
 * `azguard:permissions:grant`.
 */
final class PermissionsGrantCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:permissions:grant
        {subject : type:id, or the id when there is one subject model}
        {permission : Name, name with the panel prefix, panel:name or a pattern}
        {--panel= : The panel; otherwise the panel resolver decides}
        {--on= : type:id of the assignment scope; tenant-wide without it}
        {--until= : ISO-8601 expiry}
        {--field=* : A grant field as key=value; repeat for more}
        {--tenant= : type:id, required for a panel with tenants}
        {--origin= : The origin of the grant (manual by default)}';

    /** @var string */
    protected $description = 'Grant an AzGuard permission to a subject';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $subject = $this->subjectRef($this->stringArgument('subject'));
            $permission = $this->stringArgument('permission');
            $panel = $this->selectPanel($subject, [$permission]);
            $access = $this->access($panel, $this->originOption())->for($subject);
            $context = $this->contextOption();
            $until = $this->dateOption('until');
            $fields = $this->fields((array) $this->option('field'));

            return $this->reportChange('Grant of permission '.$permission.' to '.$subject->key(),
                $this->asSystem(static fn (): ChangeResult => $access->grantPermission($permission, $context, $until, $fields)));
        });
    }
}
