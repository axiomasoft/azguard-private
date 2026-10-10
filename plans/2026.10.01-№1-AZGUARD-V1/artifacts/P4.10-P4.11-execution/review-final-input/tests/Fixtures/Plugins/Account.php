<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Plugins;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A subject model that can sign in.
 */
final class Account extends Authenticatable
{
    protected $table = 'accounts';
}
