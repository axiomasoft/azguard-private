<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Changes\ChangeResult;
use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use Illuminate\Console\Command;

/**
 * Revokes the stored grants of a permission or pattern of a subject in one scope and origin through the change
 * pipeline, as the system actor `azguard:permissions:revoke`.
 */
final class PermissionsRevokeCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:permissions:revoke
        {subject : type:id, or the id when there is one subject model}
        {permission : Name, name with the panel prefix, panel:name or a pattern}
        {--panel= : The panel; otherwise the panel resolver decides}
        {--on= : type:id of the assignment scope; tenant-wide without it}
        {--tenant= : type:id, required for a panel with tenants}
        {--origin= : The origin of the grants (manual by default)}';

    /** @var string */
    protected $description = 'Revoke an AzGuard permission from a subject';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $subject = $this->subjectRef($this->stringArgument('subject'));
            $permission = $this->stringArgument('permission');
            $panel = $this->selectPanel($subject, [$permission]);
            $access = $this->access($panel, $this->originOption())->for($subject);
            $context = $this->contextOption();

            return $this->reportChange('Revocation of permission '.$permission.' from '.$subject->key(),
                $this->asSystem(static fn (): ChangeResult => $access->revokePermission($permission, $context)));
        });
    }
}
