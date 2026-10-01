<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs;

use AzGuard\Concerns\HasScopedRoles;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal entity stub used in scoped-role tests.
 */
class Project extends Model
{
    use HasFactory;
    use HasScopedRoles;

    protected $fillable = ['name'];

    protected static function newFactory(): Factory
    {
        return new class extends Factory
        {
            protected $model = Project::class;

            public function definition(): array
            {
                return ['name' => 'Test Project '.fake()->unique()->numerify('###')];
            }
        };
    }
}
