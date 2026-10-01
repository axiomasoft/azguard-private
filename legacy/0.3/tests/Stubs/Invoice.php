<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model
{
    protected $table = 'invoices';
}
