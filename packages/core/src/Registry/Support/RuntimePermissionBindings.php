<?php

declare(strict_types=1);

namespace AzGuard\Registry\Support;

/**
 * Optional diagnostic contributor for permissions enforced outside GateAbility.
 *
 * Reports exact keys from active runtime bindings, never from definition metadata.
 * With no tagged contributors, validation retains its core-only policy checks.
 *
 * @internal
 */
interface RuntimePermissionBindings
{
    public const string TAG = 'azguard.catalog_runtime_bindings';

    /** @return list<string> */
    public function keys(string $panelId): array;
}
