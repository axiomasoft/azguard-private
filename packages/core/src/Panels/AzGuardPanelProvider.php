<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\AzGuard;
use AzGuard\Contracts\Panels\PanelRegistry;
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
            return new PanelAccess(view: true, actions: self::ACTIONS, reason: null);
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
                $panelAccess = $panelAccess->inTenant(
                    $definition === null ? TenantRef::global() : TenantRef::of($definition->type(), $tenantKey),
                );
            }

            $subjectAccess = $panelAccess->for($subject);

            if ($subjectAccess->roleNames()->isEmpty()) {
                return PanelAccess::deny('panel_access_denied');
            }

            $entry = $target->entry();

            if ($entry !== null && ! $subjectAccess->hasPermission($entry)) {
                return PanelAccess::deny('entry_permission_denied');
            }
        } catch (UnknownPanelException) {
            return PanelAccess::deny('unknown_panel');
        } catch (Throwable) {
            return PanelAccess::deny('panel_access_error');
        }

        return new PanelAccess(view: true, actions: self::ACTIONS, reason: null);
    }
}
