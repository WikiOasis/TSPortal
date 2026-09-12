<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'real_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'flags' => [],
            'granted_flags' => [],
            'active' => true,
        ];
    }
}
