<?php

namespace Database\Factories;

use App\Models\Funnel;
use App\Models\FunnelStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FunnelStep>
 */
class FunnelStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'funnel_id' => Funnel::factory(),
            'position' => 1,
            'name' => 'Landing page',
            'type' => 'url',
            'event_name' => null,
            'path' => '/',
            'path_operator' => 'exact',
        ];
    }
}
