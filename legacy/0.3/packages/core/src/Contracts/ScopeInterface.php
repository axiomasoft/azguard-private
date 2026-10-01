<?php

declare(strict_types=1);

namespace AzGuard\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @api */
interface ScopeInterface
{
    /** @param Builder<Model> $builder */
    public function apply(Builder $builder, Model $user, ?Model $entity): void;
}
