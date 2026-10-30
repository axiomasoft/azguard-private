<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Internal;

use AzGuard\Catalog\Internal\PanelCatalog;
use AzGuard\Catalog\Internal\RoleCompiler;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Roles\GrantedAutomatically;
use AzGuard\Sources\Internal\EvaluatesAutomaticRoles;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

/** @phpstan-import-type CompiledRole from RoleCompiler */
final class ContributionQualifier
{
    /** @return array{CompiledRole|null, ?string} */
    public static function qualify(Source $source, Grant|RoleContribution $item, PanelCatalog $catalog, Panel $panel, TenantRef $tenant, DateTimeImmutable $now): array
    {
        if (! $item->activeAt($now)) {
            return [null, 'expired'];
        }

        $role = $item->role === null ? null : ($catalog->roles()[$item->role->key()] ?? null);
        $automatic = $item instanceof RoleContribution && $source instanceof EvaluatesAutomaticRoles && $role !== null
            && is_subclass_of($role['class'], GrantedAutomatically::class);

        if ($item->role !== null && ($role === null || (! $role['grantable'] && ! $automatic))) {
            $current = null;
            foreach ($catalog->roles() as $candidate) {
                if (in_array($item->role->key(), $candidate['former_keys'], true)) {
                    $current = $candidate['key'];

                    break;
                }
            }
            $reason = $current !== null ? 'former_role_key' : ($role === null ? 'unknown_role' : 'not_grantable_role');
            Log::notice('AzGuard ignored role contribution.', ['component' => $source::class, 'reason' => $reason, 'role' => $item->role->full(), 'current_key' => $current ?? $role['key'] ?? null]);

            return [null, $reason];
        }

        if (! $item->scope->tenant->equals($tenant)
            && ($role === null || ! in_array($role['class'], $panel->tenants()->globalRoles(), true))) {
            return [$role, 'global_role_not_allowed'];
        }

        if ($role !== null && ($item->scope->context->isGlobal() ? $role['scope_required'] : ! in_array($item->scope->context->type(), array_column($role['scopes'], 'type'), true))) {
            return [$role, 'role_scope_not_accepted'];
        }

        return [$role, null];
    }
}
