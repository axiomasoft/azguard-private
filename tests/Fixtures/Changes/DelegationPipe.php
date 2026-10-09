<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Changes;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use Closure;

/**
 * Delegation as an application pipe: the actor may hand out only the roles and contexts delegated to it,
 * never a super admin role or a wildcard. The core holds no delegation policy.
 */
final class DelegationPipe
{
    /** @var array<string, array{roles: list<string>, contexts: list<string>}> actor key => what the actor may grant */
    public static array $delegations = [];

    /** @var list<array{actor: ?string, user: mixed, role: ?string}> */
    public static array $observed = [];

    public function handle(Change $change, Closure $next): ChangeResult
    {
        if (! $change->type->isGrant()) {
            return $next($change);
        }
        $context = $change->context();
        $actor = $change->actor === null ? null : $change->actor->type.':'.$change->actor->id;
        self::$observed[] = ['actor' => $actor, 'user' => $context->user?->getKey(), 'role' => $context->role === null ? null : $context->role::class];
        $allowed = $actor === null ? null : (self::$delegations[$actor] ?? null);

        if ($allowed === null) {
            $change->cancel('No delegation for this actor.');
        }

        if ($change->permission !== null && ! $change->permission->isExact()) {
            $change->cancel('Wildcards are not delegated.');
        }

        if ($context->role?->superAdmin() === true) {
            $change->cancel('Super admin roles are not delegated.');
        }

        if ($change->role !== null && ! in_array($change->role->key(), $allowed['roles'], true)) {
            $change->cancel('Role '.$change->role->key().' is not delegated.');
        }

        if (! in_array($change->scope->context->key(), $allowed['contexts'], true)) {
            $change->cancel('Context '.$change->scope->context->key().' is not delegated.');
        }

        return $next($change);
    }

    public static function reset(): void
    {
        self::$delegations = [];
        self::$observed = [];
    }
}
