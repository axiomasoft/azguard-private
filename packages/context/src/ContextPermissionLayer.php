<?php

declare(strict_types=1);

namespace AzGuard\Context;

use AzGuard\Context\Contracts\MergeStrategy;
use AzGuard\Contracts\PermissionLayer;
use AzGuard\Registry\Values\PermissionSet;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * Applies the configured MergeStrategy to the globally-resolved permissions.
 *
 * Runs as a {@see PermissionLayer} after EffectivePermissionResolver has unioned
 * every global GrantSource — so a strategy can also restrict (ContextOnly,
 * DenyWithoutContext), which a plain union GrantSource cannot.
 *
 * Reads context permissions from az_guard_context_roles for the active
 * AuthorizationContext (model + context_type + context_id + panel_id).
 */
final readonly class ContextPermissionLayer implements PermissionLayer
{
    public function __construct(
        private AuthorizationContextManager $manager,
        private MergeStrategy $strategy,
    ) {}

    #[Override]
    public function apply(PermissionSet $global, Authenticatable $user, string $panelId): PermissionSet
    {
        $context = $this->manager->current($panelId);

        // No context set — the strategy decides (e.g. deny, or pass global through).
        if (! $context instanceof AuthorizationContext) {
            return $this->strategy->merge($global, null);
        }

        return $this->strategy->merge($global, $this->contextPermissions($user, $context, $panelId));
    }

    #[Override]
    public function cacheDiscriminator(string $panelId): string
    {
        $context = $this->manager->current($panelId);

        return $context instanceof AuthorizationContext
            ? "ctx:{$context->contextType}:{$context->contextId}"
            : '';
    }

    private function contextPermissions(
        Authenticatable $user,
        AuthorizationContext $context,
        string $panelId,
    ): PermissionSet {
        $table = config('az-guard-context.table_names.context_roles', 'az_guard_context_roles');

        $keys = DB::table($table)
            ->where('model_type', $user::class)
            ->where('model_id', $user->getAuthIdentifier())
            ->where('context_type', $context->contextType)
            ->where('context_id', $context->contextId)
            ->where('panel_id', $panelId)
            ->pluck('permission_key')
            ->all();

        return PermissionSet::fromRawKeys($keys);
    }
}
