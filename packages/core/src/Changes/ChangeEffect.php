<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use Packman\Attributes\Api;
use Packman\Attributes\Internal;

/**
 * What the writer did to one stored grant or dynamic permission, with the record before and after. `eventId`
 * identifies the effect for delivery after commit. A deletion `expired` is a grant removed because its expiry had
 * passed. A touch of the panel state is an `Updated` effect without records: `previousVersion` and `reason` describe it.
 */
#[Api(since: '1.0')]
final readonly class ChangeEffect
{
    /*
 */
    #[Internal('built by the writer')]
    public function __construct(
        public EffectKind $kind,
        public ChangeType $type,
        public GrantRecord|PermissionRecord|null $before,
        public GrantRecord|PermissionRecord|null $after,
        public string $eventId,
        public bool $expired = false,
        public ?int $previousVersion = null,
        public ?string $reason = null,
    ) {}
}
