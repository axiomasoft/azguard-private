<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use Illuminate\Database\Eloquent\Model;

final class Organization extends Model
{
    public function getMorphClass(): string
    {
        return 'org';
    }
}
