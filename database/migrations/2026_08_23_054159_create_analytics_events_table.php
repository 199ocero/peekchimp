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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('event_name', 100);
            $table->string('platform', 24)->default('web');
            $table->string('visitor_id', 64);
            $table->string('session_id', 64);
            $table->string('path', 2048)->nullable();
            $table->string('referrer_host', 255)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('device', 32)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('operating_system', 64)->nullable();
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 160)->nullable();
            $table->json('properties')->nullable();
            $table->timestampTz('occurred_at')->index();
            $table->timestamps();

            $table->unique(['project_id', 'event_id']);
            $table->index(['project_id', 'occurred_at']);
            $table->index(['project_id', 'event_name', 'occurred_at']);
            $table->index(['project_id', 'visitor_id', 'occurred_at']);
            $table->index(['project_id', 'session_id', 'occurred_at']);
            $table->index(['project_id', 'path', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
