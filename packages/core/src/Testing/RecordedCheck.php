<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use AzGuard\Events\AccessDecided;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\Effect;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;

/**
 * One check the fake saw: who asked for which permission in which scope and what the real engine decided.
 *
 * @api
 */
final readonly class RecordedCheck
{
    public function __construct(
        public string $panel,
        public SubjectRef $subject,
        public PermissionKey $permission,
        public TenantRef $tenant,
        public AssignmentScopeRef $context,
        public Effect $effect,
        public DecisionReason $reason,
        public ?string $component,
    ) {}

    public static function of(AccessDecided $event): self
    {
        return new self($event->panel, $event->subject, $event->permission, $event->tenant, $event->context, $event->effect, $event->reason, $event->component);
    }
}
