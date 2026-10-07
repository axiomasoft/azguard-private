<?php

declare(strict_types=1);

namespace AzGuard\Tests\Feature\Directories\Support;

use Illuminate\Database\Eloquent\Model;

final class Person extends Model
{
    protected $table = 'people';

    protected $guarded = [];

    public $timestamps = false;

    public function getMorphClass(): string
    {
        return 'dir.person';
    }

    /** @return list<string> */
    public static function azguardSearchColumns(): array
    {
        return ['name', 'email'];
    }

    public function azguardLabel(): string
    {
        return $this->getAttribute('name').' <'.$this->getAttribute('email').'>';
    }
}
