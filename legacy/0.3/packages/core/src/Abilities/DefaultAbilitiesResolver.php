<?php

declare(strict_types=1);

namespace AzGuard\Abilities;

use AzGuard\Contracts\AbilitiesResolver;
use AzGuard\Contracts\PermissionResolverInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

/**
 * Default {@see AbilitiesResolver}: resolves the user's permission set once and
 * evaluates only the requested keys against it. Short keys are scoped to the
 * panel; the returned map is keyed by the original requested keys.
 *
 * @internal
 */
final readonly class DefaultAbilitiesResolver implements AbilitiesResolver
{
    public function __construct(private PermissionResolverInterface $resolver) {}

    #[Override]
    public function forUser(Authenticatable $user, string $panelId, array $keys): array
    {
        $set = $this->resolver->forUser($user, $panelId);
        $prefix = $panelId.'.';

        $map = [];

        foreach ($keys as $key) {
            $resolved = str_starts_with($key, $prefix) ? $key : $prefix.$key;
            $map[$key] = $set->grants($resolved);
        }

        return $map;
    }
}
