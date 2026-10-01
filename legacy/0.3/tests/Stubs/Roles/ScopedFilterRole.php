<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Roles;

use AzGuard\Contracts\ScopeInterface;
use AzGuard\Roles\BaseRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Role logic that also implements ScopeInterface, so bootHasScopedRoles()
 * applies it as an Eloquent query-scope filter — narrows the query to the
 * single scoped entity, making the filter's effect observable in a test.
 */
class ScopedFilterRole extends BaseRole implements ScopeInterface
{
    public function permissions(): array
    {
        return ['*'];
    }

    public function apply(Builder $builder, Model $user, ?Model $entity): void
    {
        if ($entity !== null) {
            $builder->where($entity->getKeyName(), $entity->getKey());
        }
    }
}
