<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'theme_preference' => 'system',
            'is_disabled' => false,
            'is_locked' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user's email address is unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_disabled' => true,
            'disabled_at' => now(),
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_locked' => true,
            'locked_at' => now(),
        ]);
    }
}
