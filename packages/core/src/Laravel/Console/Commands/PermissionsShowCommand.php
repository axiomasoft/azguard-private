<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use DateTimeZone;
use Illuminate\Console\Command;

/**
 * Shows the resulting permission set of one subject in one panel, tenant and scope: the roles it holds there, whether
 * it is a super admin and the Grants permissions. Not a decision; `azguard:explain` answers one check. Reads only.
 */
final class PermissionsShowCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:permissions:show
        {subject : type:id, or the id when there is one subject model}
        {--panel= : The panel; otherwise the panel resolver decides}
        {--on= : type:id of an assignment scope}
        {--tenant= : type:id, required for a panel with tenants}
        {--json : Print JSON}';

    /** @var string */
    protected $description = 'Show the roles and permissions an AzGuard subject holds';

    public function handle(): int
    {
        return $this->attempt(function (): int {
            $subject = $this->subjectRef($this->stringArgument('subject'));
            $panel = $this->selectPanel($subject);
            $context = $this->contextOption();
            $access = $this->access($panel)->for($subject);
            $set = $access->permissionSet($context);
            $result = [
                'panel' => $panel->id(),
                'tenant' => $this->tenantOf($panel)->key(),
                'subject' => $subject->key(),
                'context' => $context?->key(),
                'super_admin' => $access->isSuperAdmin($context),
                'roles' => $access->roleNames($context)->all(),
                'permissions' => $access->permissionNames($context)->all(),
                'valid_until' => $set->validUntil()?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            ];

            if ($this->option('json') === true) {
                $this->printJson($result);

                return self::SUCCESS;
            }
            $this->line('Subject '.$result['subject'].' in panel '.$result['panel'].', tenant '.$result['tenant'].($context === null ? '' : ', scope '.$context->key()));
            $this->line('Super admin: '.($result['super_admin'] ? 'yes' : 'no'));
            $this->line('Roles: '.($result['roles'] === [] ? '-' : implode(', ', $result['roles'])));
            $this->line('Permissions: '.($result['permissions'] === [] ? '-' : implode(', ', $result['permissions'])));

            if ($result['valid_until'] !== null) {
                $this->line('Valid until: '.$result['valid_until']);
            }

            return self::SUCCESS;
        });
    }
}
