<?php
// Source: anonymized production Laravel project
namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

final class UserFactory extends Factory
{
    public function definition(): array { return ['email' => fake()->safeEmail()]; }
}
