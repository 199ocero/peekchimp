<?php

namespace Database\Factories;

use App\Models\AiVisibilityScan;
use App\Models\Project;
use App\Models\WebsitePageSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebsitePageSnapshot>
 */
class WebsitePageSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'ai_visibility_scan_id' => AiVisibilityScan::factory(),
            'url' => 'https://example.test/'.fake()->slug(),
            'url_hash' => fn (array $attributes): string => hash('sha256', (string) $attributes['url']),
            'normalized_path' => '/'.fake()->slug(),
            'http_status' => 200,
            'content_type' => 'text/html',
            'title' => fake()->sentence(5),
            'meta_description' => fake()->sentence(12),
            'robots_directives' => ['index', 'follow'],
            'headings' => [['level' => 1, 'text' => fake()->sentence(4)]],
            'links' => [],
            'cta_candidates' => [],
            'structured_data' => [],
            'main_content' => fake()->paragraphs(3, true),
            'word_count' => 80,
            'content_hash' => fake()->sha256(),
            'response_time_ms' => 120,
            'response_bytes' => 4096,
            'redirect_chain' => [],
            'crawled_at' => now(),
        ];
    }
}
