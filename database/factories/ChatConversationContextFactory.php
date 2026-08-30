<?php

namespace Database\Factories;

use App\Models\ChatConversationContext;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;

/**
 * @extends Factory<ChatConversationContext>
 */
class ChatConversationContextFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => fn (): string => (string) Conversation::query()->create([
                'id' => (string) Str::uuid7(),
                'title' => fake()->sentence(4),
            ])->getKey(),
            'project_id' => Project::factory(),
        ];
    }
}
