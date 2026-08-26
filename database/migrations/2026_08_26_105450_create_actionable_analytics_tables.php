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
        Schema::create('analytics_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('granularity', 8);
            $table->timestampTz('bucket_start');
            $table->string('dimension', 32);
            $table->string('dimension_value', 255)->default('');
            $table->unsignedBigInteger('pageviews')->default(0);
            $table->unsignedBigInteger('visitors')->default(0);
            $table->unsignedBigInteger('visits')->default(0);
            $table->unsignedBigInteger('events')->default(0);
            $table->unsignedBigInteger('bounces')->default(0);
            $table->unsignedBigInteger('duration_seconds')->default(0);
            $table->unsignedBigInteger('conversions')->default(0);
            $table->timestamps();

            $table->unique([
                'project_id', 'granularity', 'bucket_start', 'dimension', 'dimension_value',
            ], 'analytics_rollups_unique_bucket_dimension');
            $table->index(['project_id', 'granularity', 'bucket_start']);
        });

        Schema::create('insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 80);
            $table->string('category', 32);
            $table->string('type', 64);
            $table->string('severity', 16)->default('info');
            $table->string('metric', 64);
            $table->decimal('current_value', 20, 4)->nullable();
            $table->decimal('previous_value', 20, 4)->nullable();
            $table->decimal('percentage_change', 12, 4)->nullable();
            $table->string('confidence', 16)->default('medium');
            $table->text('summary');
            $table->text('explanation')->nullable();
            $table->text('recommendation')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('period_start');
            $table->timestampTz('period_end');
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'fingerprint', 'period_start', 'period_end'], 'insights_period_fingerprint_unique');
            $table->index(['project_id', 'period_end', 'severity']);
        });

        Schema::create('workspace_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_owner_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('model', 120)->nullable();
            $table->text('api_key')->nullable();
            $table->string('base_url', 255)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->string('status', 24)->default('not_configured');
            $table->timestampTz('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_insight_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('context_hash', 80);
            $table->string('provider', 32)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('status', 24)->default('queued');
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'context_hash']);
            $table->index(['project_id', 'status', 'created_at']);
        });

        Schema::create('important_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('event_name', 100);
            $table->string('page_path', 2048)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'event_name', 'is_active']);
        });

        Schema::table('goals', function (Blueprint $table): void {
            $table->string('event_name', 100)->nullable()->change();
            $table->string('type', 16)->default('event')->after('name');
            $table->string('path', 2048)->nullable()->after('event_name');
            $table->string('path_operator', 16)->default('exact')->after('path');
            $table->dropIndex(['project_id', 'event_name', 'is_active']);
            $table->index(['project_id', 'type', 'is_active']);
        });

        Schema::create('goal_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 64);
            $table->unsignedBigInteger('event_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->unique(['goal_id', 'session_id']);
            $table->index(['project_id', 'occurred_at']);
        });

        Schema::create('funnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'is_active']);
        });

        Schema::create('funnel_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 120);
            $table->string('type', 16)->default('event');
            $table->string('event_name', 100)->nullable();
            $table->string('path', 2048)->nullable();
            $table->string('path_operator', 16)->default('exact');
            $table->timestamps();

            $table->unique(['funnel_id', 'position']);
            $table->index(['funnel_id', 'type']);
        });

        Schema::create('ai_visibility_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('queued');
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('findings')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });

        Schema::create('insight_action_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insight_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action_key', 80);
            $table->string('status', 24)->default('started');
            $table->json('metadata')->nullable();
            $table->timestampTz('acted_at')->nullable();
            $table->timestamps();

            $table->index(['insight_id', 'action_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insight_action_attempts');
        Schema::dropIfExists('ai_visibility_scans');
        Schema::dropIfExists('funnel_steps');
        Schema::dropIfExists('funnels');
        Schema::dropIfExists('goal_conversions');
        Schema::table('goals', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'type', 'is_active']);
            $table->dropColumn(['type', 'path', 'path_operator']);
            $table->string('event_name', 100)->nullable(false)->change();
            $table->index(['project_id', 'event_name', 'is_active']);
        });
        Schema::dropIfExists('important_actions');
        Schema::dropIfExists('ai_insight_runs');
        Schema::dropIfExists('workspace_ai_settings');
        Schema::dropIfExists('insights');
        Schema::dropIfExists('analytics_rollups');
    }
};
