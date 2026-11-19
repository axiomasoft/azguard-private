<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\AzGuard;
use AzGuard\Concerns\Subjects\SubjectAccess;
use AzGuard\Contracts\Panels\PanelRegistry;
use AzGuard\Exceptions\Changes\UnknownPermissionException;
use AzGuard\Exceptions\Definitions\UnknownPanelException;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Contracts\Registry\PanelProvider as PackmanPanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;
use Throwable;

/**
 * Headless Packman panel provider descriptor bridging AzGuard authorization panels.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class AzGuardPanelProvider implements PackmanPanelProvider
{
    /** @var list<string> */
    private const array ACTIONS = ['view', 'grant', 'revoke', 'manage-permissions', 'diagnose'];

    public function __construct(private ?Container $container = null) {}

    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'azguard',
            owningModule: 'axiomasoft/azguard',
            requiredCapability: null,
            supportedActions: self::ACTIONS,
            label: 'AzGuard Authorization',
        );
    }

    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        if ($context->actorKind === 'guest' || $context->actorId === null || $context->actorId === '') {
            return PanelAccess::deny('unauthenticated_actor');
        }

        if ($this->container === null || ! $this->container->bound(PanelRegistry::class) || ! $this->container->bound(AzGuard::class)) {
            return PanelAccess::deny('panel_access_unavailable');
        }

        try {
            $registry = $this->container->make(PanelRegistry::class);
            $target = $registry->find($panel->panelId);

            if (! $target instanceof Panel) {
                return PanelAccess::deny('unknown_panel');
            }

            $subject = SubjectRef::of($context->actorKind, $context->actorId);

            if (! $target->accepts($subject)) {
                return PanelAccess::deny('subject_not_accepted');
            }

            $panelAccess = $this->container->make(AzGuard::class)->panel($target->id());
            $tenantKey = $context->tenant->id();

            if ($tenantKey !== '' && $tenantKey !== 'global') {
                $definition = $target->tenants()->definition();

                if ($definition === null) {
                    return PanelAccess::deny('invalid_tenant');
                }

                $panelAccess = $panelAccess->inTenant(TenantRef::of($definition->type(), $tenantKey));
            }

            $subjectAccess = $panelAccess->for($subject);

            if ($subjectAccess->roleNames()->isEmpty()) {
                return PanelAccess::deny('panel_access_denied');
            }

            $entry = $target->entry();

            if ($entry !== null && ! $subjectAccess->hasPermission($entry)) {
                return PanelAccess::deny('entry_permission_denied');
            }

            $viewPermission = $this->checkOptionalPermission($subjectAccess, 'azguard.view');

            if ($viewPermission === false) {
                return PanelAccess::deny('view_permission_denied');
            }

            $actions = $this->authorizedActions($subjectAccess, $viewPermission ?? true);
        } catch (UnknownPanelException) {
            return PanelAccess::deny('unknown_panel');
        } catch (Throwable) {
            return PanelAccess::deny('panel_access_error');
        }

        return new PanelAccess(view: true, actions: $actions, reason: null);
    }

    /** @return list<string> */
    private function authorizedActions(SubjectAccess $subjectAccess, bool $canView): array
    {
        $authorized = [];

        foreach (self::ACTIONS as $action) {
            if ($action === 'view') {
                if ($canView) {
                    $authorized[] = $action;
                }

                continue;
            }

            if ($this->checkOptionalPermission($subjectAccess, 'azguard.'.$action) === true) {
                $authorized[] = $action;
            }
        }

        return $authorized;
    }

    private function checkOptionalPermission(SubjectAccess $subjectAccess, string $permission): ?bool
    {
        try {
            return $subjectAccess->hasPermission($permission);
        } catch (UnknownPermissionException) {
            return null;
        }
    }
}
