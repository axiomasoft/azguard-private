<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns;

use AzGuard\Concerns\HasAzGuard;
use AzGuard\Contracts\AzGuardSubject;
use Illuminate\Database\Eloquent\Model;

/** A subject of both fixture panels with the trait; `$default` overrides the panel of the model. */
class Member extends Model implements AzGuardSubject
{
    use HasAzGuard;

    public static ?string $default = null;

    protected $table = 'members';

    protected $guarded = ['is_root'];

    public $timestamps = false;

    public function getMorphClass(): string
    {
        return 'member';
    }

    public function azguardDefaultPanel(): ?string
    {
        return self::$default;
    }
}
