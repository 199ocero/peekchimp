<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('website_page_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_visibility_scan_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('url_hash', 64);
            $table->string('normalized_path', 2048);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type', 120)->nullable();
            $table->string('title', 512)->nullable();
            $table->text('meta_description')->nullable();
            $table->text('canonical_url')->nullable();
            $table->json('robots_directives')->nullable();
            $table->json('headings')->nullable();
            $table->json('links')->nullable();
            $table->json('cta_candidates')->nullable();
            $table->json('structured_data')->nullable();
            $table->text('main_content')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->string('content_hash', 64)->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->unsignedInteger('response_bytes')->default(0);
            $table->json('redirect_chain')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('crawled_at');
            $table->timestamps();

            $table->unique(['ai_visibility_scan_id', 'url_hash']);
            $table->index(['project_id', 'url_hash', 'crawled_at']);
            $table->index(['project_id', 'crawled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_page_snapshots');
    }
};
