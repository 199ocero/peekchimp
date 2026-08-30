<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WebsitePageSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<int, string> $robots_directives
 * @property array<int, array{level: int, text: string}> $headings
 * @property array<int, array{url: string, path: string|null, text: string, internal: bool, nofollow: bool}> $links
 * @property array<int, array{type: string, text: string, url: string|null}> $cta_candidates
 * @property array<int, array{valid: bool, types: array<int, string>}> $structured_data
 * @property array<int, array{url: string, status: int}> $redirect_chain
 * @property CarbonImmutable $crawled_at
 */
#[Fillable([
    'project_id', 'ai_visibility_scan_id', 'url', 'url_hash', 'normalized_path',
    'http_status', 'content_type', 'title', 'meta_description', 'canonical_url',
    'robots_directives', 'headings', 'links', 'cta_candidates', 'structured_data',
    'main_content', 'word_count', 'content_hash', 'response_time_ms',
    'response_bytes', 'redirect_chain', 'error', 'crawled_at',
])]
class WebsitePageSnapshot extends Model
{
    /** @use HasFactory<WebsitePageSnapshotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'robots_directives' => 'array',
            'headings' => 'array',
            'links' => 'array',
            'cta_candidates' => 'array',
            'structured_data' => 'array',
            'word_count' => 'integer',
            'response_time_ms' => 'integer',
            'response_bytes' => 'integer',
            'redirect_chain' => 'array',
            'crawled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<AiVisibilityScan, $this> */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(AiVisibilityScan::class, 'ai_visibility_scan_id');
    }
}
