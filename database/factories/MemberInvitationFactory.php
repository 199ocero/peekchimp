<?php

namespace Database\Factories;

use App\Models\MemberInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberInvitation>
 */
class MemberInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invited_by_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
