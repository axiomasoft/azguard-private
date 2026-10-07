<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns;

use AzGuard\Concerns\HasAzGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * A consumer CRM user that already overrides `guard()`: its own method stays in force, and panels are reached through
 * `azguard()->guard()` without a selector adapter.
 */
final class CrmCustomGuardUser extends Model
{
    use HasAzGuard;

    /** @var list<array<string>> */
    public array $guardCalls = [];

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function getMorphClass(): string
    {
        return 'crm.user';
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
