<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Internal\Console\Commands;

use AzGuard\Changes\ChangeResult;
use AzGuard\Laravel\Internal\Console\GrantCommandSupport;
use Illuminate\Console\Command;

/**
 * Revokes the stored grants of a role of a subject in one scope and origin through the change pipeline, as the system
 * actor `azguard:roles:revoke`.
 */
final class RolesRevokeCommand extends Command
{
    use GrantCommandSupport;

    /** @var string */
    protected $signature = 'azguard:roles:revoke
        {subject : type:id, or the id when there is one subject model}
        {role : Role key, panel:key or code role class}
        {--panel= : The panel; otherwise the panel resolver decides}
        {--on= : type:id of the assignment scope; tenant-wide without it}
        {--tenant= : type:id, required for a panel with tenants}
        {--origin= : The origin of the grants (manual by default)}';

    /** @var string */
    protected $description = 'Revoke an AzGuard role from a subject';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $subject = $this->subjectRef($this->stringArgument('subject'));
            $role = $this->stringArgument('role');
            $panel = $this->panelFor($subject, roles: [$role]);
            $access = $this->access($panel, $this->originOption())->for($subject);
            $context = $this->contextOption();

            return $this->reportChange('Revocation of role '.$role.' from '.$subject->key(),
                $this->asSystem(static fn (): ChangeResult => $access->revokeRole($role, $context)));
        });
    }
}
