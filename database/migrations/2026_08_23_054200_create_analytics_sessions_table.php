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
        Schema::create('analytics_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('session_id', 64);
            $table->string('visitor_id', 64);
            $table->timestampTz('started_at');
            $table->timestampTz('last_seen_at');
            $table->unsignedInteger('pageviews')->default(0);
            $table->unsignedInteger('custom_events')->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->boolean('is_bounce')->default(true);
            $table->string('entry_path', 2048)->nullable();
            $table->string('exit_path', 2048)->nullable();
            $table->string('referrer_host', 255)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('device', 32)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('operating_system', 64)->nullable();
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 160)->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'session_id']);
            $table->index(['project_id', 'started_at']);
            $table->index(['project_id', 'last_seen_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_sessions');
    }
};
