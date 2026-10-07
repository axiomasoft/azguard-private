<?php

declare(strict_types=1);

namespace AzGuard\Changes;

/**
 * What the writer did to one stored grant or dynamic permission, with the record before and after. `eventId`
 * identifies the effect for delivery after commit.
 *
 * @api
 */
final readonly class ChangeEffect
{
    /** @internal built by the writer */
    public function __construct(
        public EffectKind $kind,
        public ChangeType $type,
        public GrantRecord|PermissionRecord|null $before,
        public GrantRecord|PermissionRecord|null $after,
        public string $eventId,
    ) {}
}
