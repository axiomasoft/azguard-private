<?php

declare(strict_types=1);

namespace AzGuard\Tests\Feature\Directories\Support;

use Illuminate\Database\Eloquent\Model;

final class UnsafePerson extends Model
{
    protected $table = 'people';

    protected $guarded = [];

    public $timestamps = false;

    public function getMorphClass(): string
    {
        return 'dir.unsafe';
    }

    /** @return list<string> */
    public static function azguardSearchColumns(): array
    {
        return ['name) or (1=1'];
    }
}
