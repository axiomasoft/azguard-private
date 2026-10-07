<?php

declare(strict_types=1);

namespace AzGuard\Roles;

use AzGuard\Kernel\Identity\AccessScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A role every subject matching a code rule receives; the rule assigns the role, it never defines one.
 *
 * @spi
 */
interface GrantedAutomatically
{
    public function appliesTo(Model $subject, AccessScope $scope): bool;
}
