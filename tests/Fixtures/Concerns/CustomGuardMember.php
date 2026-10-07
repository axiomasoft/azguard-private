<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns;

use AzGuard\Concerns\HasAzGuard;
use Illuminate\Database\Eloquent\Model;

/** A model with its own `guard()`: the class method wins over the trait, panels stay reachable through `azguard()`. */
final class CustomGuardMember extends Model
{
    use HasAzGuard;

    /** @var list<array<string>> */
    public array $guardCalls = [];

    protected $table = 'members';

    protected $guarded = [];

    public $timestamps = false;

    public function getMorphClass(): string
    {
        return 'member';
    }

    /**
     * @param  array<string>  $guarded
     * @return $this
     */
    public function guard(array $guarded): static
    {
        $this->guardCalls[] = $guarded;
        $this->guarded = $guarded;

        return $this;
    }
}
