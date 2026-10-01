<?php

namespace AzGuard\Tests\Stubs;

use AzGuard\Concerns\HasDirectGrants;
use AzGuard\Contracts\HasDirectGrants as HasDirectGrantsContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * User stub with HasDirectGrants for DirectGrant tests.
 * Extends Stubs\User so factory() works through Orchestra Testbench.
 */
class UserWithDirectGrants extends User implements HasDirectGrantsContract
{
    use HasDirectGrants;

    protected $table = 'users';

    protected static function newFactory(): Factory
    {
        return new class extends Factory
        {
            protected $model = UserWithDirectGrants::class;

            public function definition(): array
            {
                return [
                    'name' => fake()->name(),
                    'email' => fake()->unique()->safeEmail(),
                    'password' => 'password',
                ];
            }
        };
    }
}
